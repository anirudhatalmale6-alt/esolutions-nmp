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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function is_string;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DeleteStoreProduct extends AbstractController
{
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

        $name = (string) $product;
        $imagePath = $product->getImagePath();

        $this->entityManager->remove($product);
        $this->entityManager->flush();

        if ($imagePath !== null) {
            $projectDir = $this->getParameter('kernel.project_dir');
            @unlink((is_string($projectDir) ? $projectDir : '') . '/public/' . $imagePath);
        }

        $this->addFlash('success', $name . ' removed from the store.');

        return $this->redirectToRoute('_store_admin');
    }
}
