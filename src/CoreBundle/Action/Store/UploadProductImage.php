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
}
