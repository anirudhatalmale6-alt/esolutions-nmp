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

namespace SolidInvoice\CoreBundle\Action\Purchase;

use SolidInvoice\CoreBundle\Entity\Purchase;
use SolidInvoice\CoreBundle\Repository\PurchaseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DeletePurchase extends AbstractController
{
    public function __construct(
        private readonly PurchaseRepository $purchaseRepository,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (! $this->isCsrfTokenValid('purchase.delete', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_purchases_list');
        }

        if (Ulid::isValid($id)) {
            $purchase = $this->purchaseRepository->find(Ulid::fromString($id));

            if ($purchase instanceof Purchase) {
                $this->purchaseRepository->delete($purchase);
                $this->addFlash('success', 'Purchase deleted.');
            }
        }

        return $this->redirectToRoute('_purchases_list');
    }
}
