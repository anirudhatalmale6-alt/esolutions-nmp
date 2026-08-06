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

namespace SolidInvoice\CoreBundle\Action\Receipt;

use SolidInvoice\CoreBundle\Entity\CustomerReceipt;
use SolidInvoice\CoreBundle\Repository\CustomerReceiptRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DeleteReceipt extends AbstractController
{
    public function __construct(
        private readonly CustomerReceiptRepository $receiptRepository,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (! $this->isCsrfTokenValid('receipt.delete', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_receipts_list');
        }

        if (Ulid::isValid($id)) {
            $receipt = $this->receiptRepository->find(Ulid::fromString($id));

            if ($receipt instanceof CustomerReceipt) {
                $this->receiptRepository->delete($receipt);
                $this->addFlash('success', 'Payment deleted.');
            }
        }

        return $this->redirectToRoute('_receipts_list');
    }
}
