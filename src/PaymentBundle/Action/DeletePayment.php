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

namespace SolidInvoice\PaymentBundle\Action;

use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\InvoiceBundle\Enum\InvoiceStatus;
use SolidInvoice\InvoiceBundle\Repository\InvoiceRepository;
use SolidInvoice\PaymentBundle\Entity\Payment;
use SolidInvoice\PaymentBundle\Repository\PaymentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/**
 * Removes a recorded payment so a mistyped amount can be corrected (delete the
 * wrong entry, then record the right one via the normal "record payment" flow).
 *
 * SolidInvoice has no built-in way to edit/undo a payment; the paid total of an
 * invoice is derived from its captured payments, so removing the payment and
 * re-deriving the balance is the clean correction. We also reopen an invoice that
 * had been auto-marked Paid but is no longer fully covered (there is no reverse
 * workflow transition, so the status is set directly).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DeletePayment extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentRepository $paymentRepository,
        private readonly InvoiceRepository $invoiceRepository,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $fallback = $this->redirectToRoute('_payments_index');

        if (! $this->isCsrfTokenValid('payment.delete', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $fallback;
        }

        if (! Ulid::isValid($id)) {
            return $fallback;
        }

        $payment = $this->paymentRepository->find(Ulid::fromString($id));

        if (! $payment instanceof Payment) {
            return $fallback;
        }

        $invoice = $payment->getInvoice();

        // Remove the payment first so the derived paid-total recalculates without it.
        $this->entityManager->remove($payment);
        $this->entityManager->flush();

        if (! $invoice instanceof Invoice) {
            $this->addFlash('success', 'Payment removed.');

            return $fallback;
        }

        // Re-derive the outstanding balance from the remaining captured payments,
        // mirroring PaymentCompleteListener.
        $totalPaid = $this->paymentRepository->getTotalPaidForInvoice($invoice);
        $invoice->setBalance($invoice->getTotal()->toBigDecimal()->minus($totalPaid));

        // If the invoice had been marked Paid but is no longer fully covered, reopen
        // it to Pending (no reverse transition exists, so set the status directly).
        if ($invoice->getStatus() === InvoiceStatus::Paid && ! $this->invoiceRepository->isFullyPaid($invoice)) {
            $invoice->setStatus(InvoiceStatus::Pending);
        }

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        $this->addFlash('success', 'Payment removed. You can now record the correct amount.');

        return $this->redirectToRoute('_invoices_view', ['id' => (string) $invoice->getId()]);
    }
}
