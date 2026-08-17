<?php

declare(strict_types=1);

/*
 * This file is part of SolidInvoice project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace SolidInvoice\CoreBundle\Marketplace;

use SolidInvoice\CoreBundle\Entity\Company;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;
use function bin2hex;
use function file_exists;
use function getimagesize;
use function imagecreatefromjpeg;
use function imagecreatefrompng;
use function imagecreatefromwebp;
use function imagecreatetruecolor;
use function imagecopyresampled;
use function imagedestroy;
use function imagefill;
use function imagecolorallocate;
use function imagejpeg;
use function imageinterlace;
use function in_array;
use function is_dir;
use function max;
use function min;
use function round;
use function imagesx;
use function imagesy;
use function mkdir;
use function random_bytes;
use function realpath;
use function str_contains;
use function str_starts_with;
use function strrpos;
use function strtolower;
use function substr;
use function unlink;

/**
 * Where Marketplace pictures live - classified adverts and community posts.
 *
 * Unlike identity documents, these are meant to be seen: the Marketplace page is
 * open to anyone, so a buyer with no account still has to be able to load them.
 * They are still kept under var/ rather than in public/, and handed out by
 * {@see \SolidInvoice\CoreBundle\Action\Marketplace\Media}. That costs a PHP
 * request per picture, and buys two things worth more than it: the folder cannot
 * be listed or guessed at, and deploys never touch it, whatever the docroot on
 * the hosting turns out to be.
 *
 * Every picture is re-encoded on the way in. A phone photo is four megabytes and
 * four thousand pixels wide; a feed of thirty of those is unusable on the mobile
 * data most of these buyers are on. Re-encoding also means whatever was inside
 * the original file - EXIF, a payload dressed up as an image - does not survive
 * into what gets served.
 */
final class MediaStore
{
    /** What a member may hand us. */
    private const array ALLOWED = ['jpg', 'jpeg', 'png', 'webp'];

    /** 8 MB in, always much smaller out. */
    private const int MAX_BYTES = 8 * 1024 * 1024;

    /** Wide enough to fill a banner on a laptop, small enough for a phone. */
    private const int MAX_WIDTH = 1280;

    private const int MAX_HEIGHT = 1280;

    private const int QUALITY = 82;

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        return self::ALLOWED;
    }

    public function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    /**
     * Store one uploaded picture and return its path relative to the marketplace
     * media folder - that is what goes on the row.
     *
     * @throws MediaUploadFailed
     */
    public function store(?Company $company, string $kind, UploadedFile $file): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new MediaUploadFailed(
                in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'That picture is too large. Please use one under 8 MB.'
                    : 'That picture could not be uploaded, please try again.'
            );
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new MediaUploadFailed('That picture is too large. Please use one under 8 MB.');
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED, true)) {
            throw new MediaUploadFailed('Please upload a JPG, PNG or WEBP picture.');
        }

        $source = $file->getPathname();

        // What the file claims to be in its name counts for nothing. Read the
        // first bytes and go by that, so an .jpg that is not an image is turned
        // away here rather than served to everybody who opens the page.
        $info = @getimagesize($source);

        if ($info === false) {
            throw new MediaUploadFailed('That file is not a picture we can read. Please upload a JPG, PNG or WEBP.');
        }

        $directory = $this->directoryFor($company);

        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new MediaUploadFailed('Could not save the picture, please try again.');
        }

        $filename = $kind . '-' . bin2hex(random_bytes(8)) . '.jpg';

        if (! $this->writeResized($source, $directory . '/' . $filename, (int) $info[2])) {
            throw new MediaUploadFailed('Could not save the picture, please try again.');
        }

        return $this->relativeDirectory($company) . '/' . $filename;
    }

    /**
     * Absolute path of a stored picture, or null when the path is missing, points
     * outside the media folder, or is no longer on disk.
     *
     * Treated as untrusted even though it comes off our own row - a path is only
     * ever as safe as the last thing that could write it.
     */
    public function resolve(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
            return null;
        }

        $absolute = $this->root() . '/' . $relativePath;

        if (! file_exists($absolute)) {
            return null;
        }

        $real = realpath($absolute);
        $root = realpath($this->root());

        if ($real === false || $root === false || ! str_starts_with($real, $root . '/')) {
            return null;
        }

        return $real;
    }

    public function remove(?string $relativePath): void
    {
        $absolute = $this->resolve($relativePath);

        if ($absolute !== null) {
            @unlink($absolute);
        }
    }

    public function extensionOf(string $relativePath): string
    {
        $dot = strrpos($relativePath, '.');

        return $dot === false ? '' : strtolower(substr($relativePath, $dot + 1));
    }

    /**
     * Read the picture, shrink it to fit, and write it back out as a JPEG.
     *
     * Nothing is ever enlarged: a small picture stays exactly the size it was,
     * because stretching it would only make it blurry and bigger.
     */
    private function writeResized(string $source, string $destination, int $type): bool
    {
        try {
            $image = match ($type) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
                IMAGETYPE_PNG => @imagecreatefrompng($source),
                IMAGETYPE_WEBP => @imagecreatefromwebp($source),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }

        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 1 || $height < 1) {
            imagedestroy($image);

            return false;
        }

        $scale = min(self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height, 1);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($canvas === false) {
            imagedestroy($image);

            return false;
        }

        // A PNG or WEBP with transparency comes out black on a JPEG otherwise -
        // which, for a logo cut out on a transparent background, means the advert
        // somebody paid for arrives as a black rectangle.
        $white = imagecolorallocate($canvas, 255, 255, 255);

        if ($white !== false) {
            imagefill($canvas, 0, 0, $white);
        }

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        // Progressive, so a picture on a slow connection appears whole and blurry
        // rather than a quarter of it sharp.
        imageinterlace($canvas, true);

        $written = @imagejpeg($canvas, $destination, self::QUALITY);

        imagedestroy($canvas);
        imagedestroy($image);

        return $written;
    }

    private function root(): string
    {
        return $this->projectDir . '/var/marketplace';
    }

    private function directoryFor(?Company $company): string
    {
        return $this->root() . '/' . $this->relativeDirectory($company);
    }

    /**
     * Foldered by business, so it is obvious on disk whose pictures are whose -
     * and so removing a business's media is one folder, not a search.
     */
    private function relativeDirectory(?Company $company): string
    {
        $id = $company?->getId();

        return $id === null ? 'platform' : (string) $id;
    }
}
