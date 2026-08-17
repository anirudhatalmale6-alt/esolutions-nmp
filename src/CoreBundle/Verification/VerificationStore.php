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

namespace SolidInvoice\CoreBundle\Verification;

use SolidInvoice\CoreBundle\Entity\Company;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;
use function bin2hex;
use function file_exists;
use function in_array;
use function is_dir;
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
 * Where identity documents live.
 *
 * These are passports and national IDs. They are stored under var/verification,
 * which is NOT reachable over the web - the only way to read one back is
 * {@see \SolidInvoice\CoreBundle\Action\Verification\VerificationDocument},
 * which is super-admin only. Putting them in public/uploads next to the product
 * photos would have made every one of them a guessable URL away from the
 * open internet, and no amount of an unguessable filename fixes that.
 */
final class VerificationStore
{
    /** The three documents a business can send in. */
    public const string ID_FRONT = 'id_front';

    public const string ID_BACK = 'id_back';

    public const string PASSPORT = 'passport';

    public const array KINDS = [self::ID_FRONT, self::ID_BACK, self::PASSPORT];

    private const array ALLOWED = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /** 8 MB. A phone photo of a passport page is well under this. */
    private const int MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return list<string> the allowed extensions, for the accept attribute
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
     * Store one uploaded document and return the path to record on the company,
     * relative to the verification directory.
     *
     * @throws VerificationUploadFailed when the file is unusable - the caller
     *                                  turns that into a message on the page
     */
    public function store(Company $company, string $kind, UploadedFile $file): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new VerificationUploadFailed('Unknown document.');
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new VerificationUploadFailed(
                in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'That file is too large. Please use a photo under 8 MB.'
                    : 'That file could not be uploaded, please try again.'
            );
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new VerificationUploadFailed('That file is too large. Please use a photo under 8 MB.');
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED, true)) {
            throw new VerificationUploadFailed('Please upload a JPG, PNG, WEBP or PDF.');
        }

        $directory = $this->directoryFor($company);

        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new VerificationUploadFailed('Could not save the document, please try again.');
        }

        // A random name, not the one off the member's phone. The stored name is
        // never shown and never trusted, so there is nothing to gain from
        // keeping "passport final (2).jpg" and something to lose.
        $filename = $kind . '-' . bin2hex(random_bytes(8)) . '.' . $extension;

        try {
            $file->move($directory, $filename);
        } catch (Throwable) {
            throw new VerificationUploadFailed('Could not save the document, please try again.');
        }

        return $this->relativeDirectory($company) . '/' . $filename;
    }

    /**
     * Absolute path of a stored document, or null if the path is missing, points
     * outside the verification directory, or no longer exists on disk.
     *
     * Everything a caller hands in is treated as untrusted, even though it comes
     * off our own row: a path is only ever as safe as the last person who could
     * write it.
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

        // Belt and braces after the string checks above - resolve the symlinks
        // and confirm the answer is still underneath the verification directory.
        $real = realpath($absolute);
        $root = realpath($this->root());

        if ($real === false || $root === false || ! str_starts_with($real, $root . '/')) {
            return null;
        }

        return $real;
    }

    /**
     * Remove a previously stored document. Silent when it is already gone -
     * replacing a document must not fail because the old file went missing.
     */
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

    private function root(): string
    {
        return $this->projectDir . '/var/verification';
    }

    private function directoryFor(Company $company): string
    {
        return $this->root() . '/' . $this->relativeDirectory($company);
    }

    private function relativeDirectory(Company $company): string
    {
        return (string) $company->getId();
    }
}
