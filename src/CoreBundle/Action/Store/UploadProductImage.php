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

namespace SolidInvoice\CoreBundle\Action\Store;

use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Entity\StoreProduct;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;
use function in_array;
use function is_string;
use function preg_replace;
use function strlen;
use function strtolower;
use function trim;

/**
 * Uploads (or replaces) the customer-facing photo for one store product. The
 * owner does this from the admin list after importing the product sheet.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class UploadProductImage extends AbstractController
{
    private const ALLOWED = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        // A phone photo that is larger than PHP's post_max_size makes the whole
        // POST body get discarded before it reaches us - the file AND the CSRF
        // token both arrive empty, which used to surface as a misleading
        // "session expired" error. Detect that case up front and tell the owner
        // the real reason so they don't keep retrying the same big picture.
        $postMax = $this->toBytes((string) ini_get('post_max_size'));
        $contentLength = (int) $request->server->get('CONTENT_LENGTH', 0);
        if ($postMax > 0 && $contentLength > $postMax && $request->files->count() === 0) {
            $this->addFlash('error', 'That photo is too large to upload. Please pick a smaller image and try again.');

            return $this->redirectToRoute('_store_admin');
        }

        if (! $this->isCsrfTokenValid('store.product', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_store_admin');
        }

        $product = $this->entityManager->find(StoreProduct::class, $id);

        if (! $product instanceof StoreProduct) {
            $this->addFlash('error', 'Product not found.');

            return $this->redirectToRoute('_store_admin');
        }

        $file = $request->files->get('image');

        if (! $file instanceof UploadedFile) {
            $this->addFlash('error', 'Please choose an image to upload.');

            return $this->redirectToRoute('_store_admin');
        }

        // A file that is over upload_max_filesize (but under post_max_size) does
        // arrive, but flagged with an upload error and no usable contents.
        if ($file->getError() !== UPLOAD_ERR_OK) {
            $tooBig = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            $this->addFlash('error', $tooBig
                ? 'That photo is too large to upload. Please pick a smaller image and try again.'
                : 'The photo could not be uploaded, please try again.');

            return $this->redirectToRoute('_store_admin');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, self::ALLOWED, true)) {
            $this->addFlash('error', 'Please upload a JPG, PNG or WEBP image.');

            return $this->redirectToRoute('_store_admin');
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $targetDir = (is_string($projectDir) ? $projectDir : '') . '/public/uploads/products';

        $base = $this->slug($product->getSku() !== '' ? $product->getSku() : (string) $product->getId());
        if ($base === '') {
            $base = 'product';
        }
        $filename = $base . '-' . substr((string) $product->getId(), -6) . '.' . $extension;

        try {
            $file->move($targetDir, $filename);
        } catch (Throwable $e) {
            $this->addFlash('error', 'Could not save the image: ' . $e->getMessage());

            return $this->redirectToRoute('_store_admin');
        }

        // Remove the previous image if it was a different file.
        $previous = $product->getImagePath();
        $webPath = 'uploads/products/' . $filename;
        if ($previous !== null && $previous !== $webPath) {
            @unlink((is_string($projectDir) ? $projectDir : '') . '/public/' . $previous);
        }

        $product->setImagePath($webPath);
        $this->entityManager->flush();

        $this->addFlash('success', 'Photo updated for ' . $product . '.');

        return $this->redirectToRoute('_store_admin');
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim($value, '-');
    }

    /**
     * Convert a php.ini shorthand size (e.g. "8M", "2G", "512K") into bytes.
     */
    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}
