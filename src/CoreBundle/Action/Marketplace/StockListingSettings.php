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

namespace SolidInvoice\CoreBundle\Action\Marketplace;

use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Form\Type\ImageUploadType;
use SolidInvoice\CoreBundle\Marketplace\MarketplaceManager;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use function base64_encode;
use function substr;
use function file_get_contents;
use function function_exists;
use function getimagesize;
use function imagecopyresampled;
use function imagecreatefromstring;
use function imagecreatetruecolor;
use function in_array;
use function max;
use function min;
use function ob_get_clean;
use function ob_start;
use function round;
use function trim;

/**
 * "Stock Listing" page: where a business decides how its stock is seen outside
 * the portal. Two independent switches:
 *
 *  - the Marketplace, where buyers search across every listed business and reach
 *    this one on WhatsApp. Off means the stock never appears in a search; with
 *    no number set the Chat Now button simply does not show;
 *  - its own public stock page, a no-login link at an address of its choosing
 *    that it hands to its own customers. Off means the link 404s.
 */
#[IsGranted('ROLE_ADMIN')]
final class StockListingSettings extends AbstractController
{
    /** Longest-side size, in pixels, the listing display picture is resized to. */
    private const THUMB_MAX = 320;

    public function __construct(
        private readonly MarketplaceManager $marketplace,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $companyId = $this->companySelector->getCompany();
        $binaryCompanyId = $companyId?->toBinary();

        if ($binaryCompanyId === null) {
            return $this->render('@SolidInvoiceCore/Marketplace/settings.html.twig', [
                'listed' => false, 'whatsapp' => '', 'country' => '', 'city' => '', 'logo' => '', 'countries' => $this->marketplace->countryChoices(),
                'shareStock' => false, 'shareSlug' => '', 'shareBase' => '', 'shareUrl' => '',
            ]);
        }

        if ($request->isMethod('POST')) {
            if (! $this->isCsrfTokenValid('marketplace.settings', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Your session expired, please try again.');

                return $this->redirectToRoute('_marketplace_settings');
            }

            $listed = $request->request->getBoolean('listed');
            $whatsapp = trim((string) $request->request->get('whatsapp', ''));
            $country = trim((string) $request->request->get('country', ''));
            $city = trim((string) $request->request->get('city', ''));

            // null = leave the current logo untouched, '' = remove it,
            // "type|base64" = a newly uploaded image to store.
            $logo = $this->readLogo($request);

            if ($logo === false) {
                return $this->redirectToRoute('_marketplace_settings');
            }

            $this->marketplace->save($binaryCompanyId, $listed, $whatsapp, $country, $city);
            $this->marketplace->saveLogo($binaryCompanyId, $logo);
            $this->saveStockShare($request, $binaryCompanyId, $companyId);

            $this->addFlash('success', $listed
                ? 'Saved - your stock is now listed on the Marketplace.'
                : 'Saved - your stock is not listed on the Marketplace.');

            return $this->redirectToRoute('_marketplace_settings');
        }

        $settings = $this->marketplace->getForCompany($binaryCompanyId);
        $shareBase = $this->shareBase();

        return $this->render('@SolidInvoiceCore/Marketplace/settings.html.twig', [
            'listed' => $settings['listed'],
            'whatsapp' => $settings['whatsapp'],
            'country' => $settings['country'],
            'city' => $settings['city'],
            'logo' => $settings['logo'],
            'countries' => $this->marketplace->countryChoices(),
            'shareStock' => $settings['shareStock'],
            // Pre-fill an address for a business that has never set one, so
            // switching the page on is a single click for most people.
            'shareSlug' => $settings['shareSlug'] !== ''
                ? $settings['shareSlug']
                : $this->marketplace->suggestSlug($this->companyName($companyId), $binaryCompanyId),
            'shareBase' => $shareBase,
            'shareUrl' => $settings['shareStock'] && $settings['shareSlug'] !== ''
                ? $shareBase . $settings['shareSlug']
                : '',
        ]);
    }

    /**
     * Store the company's own public stock page settings.
     *
     * A refused address (blank, or already taken by another business) must not
     * silently switch the page on at some address the owner did not choose, so
     * the save is skipped entirely and the reason is flashed back.
     */
    private function saveStockShare(Request $request, string $binaryCompanyId, Ulid $companyId): void
    {
        $shareStock = $request->request->getBoolean('share_stock');
        $slug = $this->marketplace->normalizeSlug((string) $request->request->get('share_slug', ''));

        if ($shareStock && $slug === '') {
            $slug = $this->marketplace->suggestSlug($this->companyName($companyId), $binaryCompanyId);
        }

        // Off keeps the address on file, so turning the page back on later returns
        // the same link rather than a new one customers have not got.
        if (! $this->marketplace->saveStockShare($binaryCompanyId, $shareStock, $slug)) {
            $this->addFlash('error', 'That stock page address is already in use by another business - please choose another.');
        }
    }

    private function companyName(Ulid $companyId): string
    {
        return (string) $this->companyRepository->find($companyId)?->getName();
    }

    /**
     * The public stock page address without the business's own part, e.g.
     * "https://esolutions.website/inventory/". Built from the route so it stays
     * right if the path ever moves.
     */
    private function shareBase(): string
    {
        $sample = $this->generateUrl('_stock_public_member', ['slug' => 'aaa'], UrlGeneratorInterface::ABSOLUTE_URL);

        return substr($sample, 0, -3);
    }

    /**
     * Read the uploaded listing logo from the request. Returns "type|base64" for
     * a valid new image, '' if the seller asked to remove it, null to leave the
     * current logo as-is, or false when the upload was rejected (a flash message
     * is set) so the caller can bounce back to the form.
     */
    private function readLogo(Request $request): string|false|null
    {
        if ($request->request->getBoolean('remove_logo')) {
            return '';
        }

        $file = $request->files->get('logo');

        if (! $file instanceof UploadedFile) {
            return null;
        }

        if (! $file->isValid()) {
            $this->addFlash('error', 'The logo could not be uploaded, please try again.');

            return false;
        }

        // ~1.5 MB cap so the Marketplace page stays light (the image is inlined).
        if ($file->getSize() > 1_500_000) {
            $this->addFlash('error', 'The logo is too large - please use an image under 1.5 MB.');

            return false;
        }

        if (! in_array((string) $file->getMimeType(), ImageUploadType::ALLOWED_MIME_TYPES, true)
            || @getimagesize($file->getPathname()) === false) {
            $this->addFlash('error', 'The logo must be a JPEG, PNG, GIF or WebP image.');

            return false;
        }

        // Shrink the display picture to a small thumbnail so the Marketplace page
        // (which inlines the image) stays light and the value comfortably fits the
        // settings column. Falls back to the original bytes if GD is unavailable.
        $thumbnail = $this->thumbnail($file->getPathname(), (string) $file->getMimeType());

        if ($thumbnail !== null) {
            return $thumbnail;
        }

        return $file->guessExtension() . '|' . base64_encode((string) file_get_contents($file->getPathname()));
    }

    /**
     * Resize an uploaded image down to fit within {@see self::THUMB_MAX} px on its
     * longest side and re-encode it, returning "type|base64" - or null when GD
     * cannot handle it, so the caller keeps the original bytes. Transparency is
     * preserved (non-JPEG sources are written as PNG).
     */
    private function thumbnail(string $path, string $mime): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            return null;
        }

        $src = @imagecreatefromstring($bytes);

        if ($src === false) {
            return null;
        }

        $width = imagesx($src);
        $height = imagesy($src);
        $scale = min(1.0, self::THUMB_MAX / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        $isJpeg = $mime === 'image/jpeg';

        if (! $isJpeg) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();

        if ($isJpeg) {
            imagejpeg($dst, null, 85);
            $type = 'jpg';
        } else {
            imagepng($dst, null, 6);
            $type = 'png';
        }

        $encoded = (string) ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        if ($encoded === '') {
            return null;
        }

        return $type . '|' . base64_encode($encoded);
    }
}
