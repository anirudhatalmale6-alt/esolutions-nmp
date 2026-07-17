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

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use SolidInvoice\CoreBundle\Entity\Purchase;
use SolidInvoice\CoreBundle\Entity\PurchasePayment;
use SolidInvoice\CoreBundle\Repository\PurchaseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use Throwable;
use function is_numeric;
use function trim;

/**
 * Records a single dated payment against a purchase order - the supplier-side
 * equivalent of paying an invoice. Adds one PurchasePayment (amount + date) and
 * re-derives the amount paid from all payments, so each payment shows on its own
 * day in the daily ledger.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class PayPurchase extends AbstractController
{
    public function __construct(
        private readonly PurchaseRepository $purchaseRepository,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (! Ulid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        $purchase = $this->purchaseRepository->find(Ulid::fromString($id));

        if (! $purchase instanceof Purchase) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            return $this->save($request, $purchase);
        }

        return $this->renderForm($purchase, [
            'payment_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
            'amount' => $purchase->getBalance(),
        ]);
    }

    private function save(Request $request, Purchase $purchase): Response
    {
        if (! $this->isCsrfTokenValid('purchase.pay', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirect($request->getUri());
        }

        $data = [
            'payment_date' => trim((string) $request->request->get('payment_date')),
            'amount' => trim((string) $request->request->get('amount')),
        ];

        if ($data['amount'] === '' || ! is_numeric($data['amount']) || BigDecimal::of($data['amount'])->isNegativeOrZero()) {
            $this->addFlash('error', 'Please enter a payment amount greater than zero.');

            return $this->renderForm($purchase, $data);
        }

        try {
            $paymentDate = $data['payment_date'] !== ''
                ? new DateTimeImmutable($data['payment_date'])
                : new DateTimeImmutable('today');
        } catch (Throwable) {
            $this->addFlash('error', 'Please enter a valid date.');

            return $this->renderForm($purchase, $data);
        }

        $amount = BigDecimal::of($data['amount'])->toScale(2, RoundingMode::HalfUp);

        $payment = new PurchasePayment();
        $payment->setPaymentDate($paymentDate)
            ->setAmount((string) $amount);

        $purchase->addPayment($payment);
        $purchase->recalculateAmountPaidFromPayments();

        $this->purchaseRepository->save($purchase);

        $this->addFlash('success', 'Payment of AED ' . (string) $amount . ' recorded.');

        return $this->redirectToRoute('_purchase_view', ['id' => (string) $purchase->getId()]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderForm(Purchase $purchase, array $data): Response
    {
        return $this->render('@SolidInvoiceCore/Purchase/pay.html.twig', [
            'purchase' => $purchase,
            'data' => $data,
        ]);
    }
}
