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

namespace SolidInvoice\CoreBundle\Action\Report;

use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Repository\CreditNoteRepository;
use SolidInvoice\CoreBundle\Repository\ExpenseRepository;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\PaymentBundle\Entity\Payment;
use SolidInvoice\PaymentBundle\Enum\PaymentStatus;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;
use function trim;

/**
 * One-click "close the day" ledger. For a chosen date (today by default) it
 * pulls together, in one view:
 *   - Money IN  : customer payments received that day (captured)
 *   - Money OUT : supplier payments (purchase orders dated that day) + expenses
 *   - The invoices raised that day (count + total billed)
 *   - The net cash movement for the day
 *
 * Customer payments and invoices are stored in MINOR units (fils/cents) and are
 * divided by 100; supplier payments and expenses are already in major units.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class DailyLedger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExpenseRepository $expenseRepository,
        private CreditNoteRepository $creditNoteRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[Template('@SolidInvoiceCore/Report/daily_ledger.html.twig')]
    public function __invoke(Request $request): array
    {
        $date = $this->resolveDate((string) $request->query->get('date', ''));
        $start = new DateTimeImmutable($date->format('Y-m-d') . ' 00:00:00');
        $end = new DateTimeImmutable($date->format('Y-m-d') . ' 23:59:59');

        $payments = $this->paymentsReceived($start, $end);
        $suppliers = $this->supplierPayments($start, $end);
        $expenses = $this->expenseRepository->findBetween($start, $end);
        $refunds = $this->creditNoteRepository->findCashBetween($start, $end);
        $invoices = $this->invoicesRaised($start, $end);

        $moneyIn = BigDecimal::zero();
        foreach ($payments as $payment) {
            $moneyIn = $moneyIn->plus(BigDecimal::of($payment['amount']));
        }

        $supplierOut = BigDecimal::zero();
        foreach ($suppliers as $supplier) {
            $supplierOut = $supplierOut->plus(BigDecimal::of($supplier['amount']));
        }

        $expensesOut = BigDecimal::zero();
        foreach ($expenses as $expense) {
            $expensesOut = $expensesOut->plus(BigDecimal::of($expense->getAmount()));
        }

        $refundsOut = BigDecimal::zero();
        foreach ($refunds as $refund) {
            $refundsOut = $refundsOut->plus(BigDecimal::of($refund->getAmount()));
        }

        $invoicesTotal = BigDecimal::zero();
        foreach ($invoices as $invoice) {
            $invoicesTotal = $invoicesTotal->plus(BigDecimal::of($invoice['total']));
        }

        $moneyOut = $supplierOut->plus($expensesOut)->plus($refundsOut);
        $net = $moneyIn->minus($moneyOut);

        return [
            'date' => $date,
            'today' => (new DateTimeImmutable('today'))->format('Y-m-d'),
            'payments' => $payments,
            'suppliers' => $suppliers,
            'expenses' => $expenses,
            'refunds' => $refunds,
            'invoices' => $invoices,
            'moneyIn' => (string) $moneyIn->toScale(2),
            'supplierOut' => (string) $supplierOut->toScale(2),
            'expensesOut' => (string) $expensesOut->toScale(2),
            'refundsOut' => (string) $refundsOut->toScale(2),
            'moneyOut' => (string) $moneyOut->toScale(2),
            'invoicesTotal' => (string) $invoicesTotal->toScale(2),
            'net' => (string) $net->toScale(2),
        ];
    }

    private function resolveDate(string $raw): DateTimeImmutable
    {
        if ($raw !== '') {
            try {
                return new DateTimeImmutable($raw);
            } catch (Throwable) {
                // fall through to today
            }
        }

        return new DateTimeImmutable('today');
    }

    /**
     * Captured customer payments completed within the day (money IN), major units.
     *
     * @return list<array{client: string, invoiceId: string, amount: string, time: ?\DateTimeInterface}>
     */
    private function paymentsReceived(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $rows = $this->entityManager->createQuery(
            'SELECT p.totalAmount AS amount, p.completed AS completed, c.name AS client, inv.invoiceId AS invoiceId
             FROM ' . Payment::class . ' p
             LEFT JOIN p.client c
             LEFT JOIN p.invoice inv
             WHERE p.status = :captured AND p.completed BETWEEN :start AND :end
             ORDER BY p.completed ASC'
        )
            ->setParameter('captured', PaymentStatus::Captured->value)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getResult();

        $payments = [];

        foreach ($rows as $row) {
            $payments[] = [
                'client' => (string) ($row['client'] ?? '—'),
                'invoiceId' => (string) ($row['invoiceId'] ?? ''),
                'amount' => $this->toMajor((string) ($row['amount'] ?? '0')),
                'time' => $row['completed'] ?? null,
            ];
        }

        return $payments;
    }

    /**
     * Supplier payments made within the day (major units).
     *
     * A purchase is now paid via one or more dated PurchasePayment rows, so each
     * payment lands on the exact day it was made. For older purchases that were
     * recorded before dated payments existed (no payment rows, just a single
     * amount-paid figure), fall back to the old behaviour of attributing the paid
     * amount to the purchase-order date, so historic days keep showing correctly.
     *
     * @return list<array{supplier: string, reference: string, amount: string, balance: string}>
     */
    private function supplierPayments(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $suppliers = [];

        // Dated payments (current model): one entry per payment on its own day.
        $rows = $this->entityManager->createQuery(
            'SELECT c.name AS supplier, pu.reference AS reference, pp.amount AS amount,
                    pu.totalAmount AS total, pu.amountPaid AS paid
             FROM SolidInvoice\CoreBundle\Entity\PurchasePayment pp
             JOIN pp.purchase pu
             JOIN pu.client c
             WHERE pp.paymentDate BETWEEN :start AND :end
             ORDER BY pp.paymentDate ASC'
        )
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getResult();

        foreach ($rows as $row) {
            $total = BigDecimal::of((string) ($row['total'] ?? '0'));
            $paid = BigDecimal::of((string) ($row['paid'] ?? '0'));
            $balance = $total->minus($paid);

            if ($balance->isNegative()) {
                $balance = BigDecimal::zero();
            }

            $suppliers[] = [
                'supplier' => (string) ($row['supplier'] ?? '—'),
                'reference' => (string) ($row['reference'] ?? ''),
                'amount' => (string) BigDecimal::of((string) ($row['amount'] ?? '0'))->toScale(2),
                'balance' => (string) $balance->toScale(2),
            ];
        }

        // Legacy purchases with no dated payment rows: attribute the paid amount
        // to the purchase date, exactly as before.
        $legacy = $this->entityManager->createQuery(
            'SELECT c.name AS supplier, pu.reference AS reference, pu.amountPaid AS amount, pu.totalAmount AS total
             FROM SolidInvoice\CoreBundle\Entity\Purchase pu
             JOIN pu.client c
             WHERE pu.purchaseDate BETWEEN :start AND :end AND pu.amountPaid > 0 AND SIZE(pu.payments) = 0
             ORDER BY pu.created ASC'
        )
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getResult();

        foreach ($legacy as $row) {
            $paid = BigDecimal::of((string) ($row['amount'] ?? '0'));
            $total = BigDecimal::of((string) ($row['total'] ?? '0'));
            $balance = $total->minus($paid);

            if ($balance->isNegative()) {
                $balance = BigDecimal::zero();
            }

            $suppliers[] = [
                'supplier' => (string) ($row['supplier'] ?? '—'),
                'reference' => (string) ($row['reference'] ?? ''),
                'amount' => (string) $paid->toScale(2),
                'balance' => (string) $balance->toScale(2),
            ];
        }

        return $suppliers;
    }

    /**
     * Invoices raised within the day (count + total billed), total major units.
     *
     * @return list<array{invoiceId: string, client: string, total: string, status: string}>
     */
    private function invoicesRaised(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $rows = $this->entityManager->createQuery(
            'SELECT inv.invoiceId AS invoiceId, inv.total AS total, inv.balance AS balance, inv.status AS status, c.name AS client
             FROM ' . Invoice::class . ' inv
             JOIN inv.client c
             WHERE inv.invoiceDate BETWEEN :start AND :end
             ORDER BY inv.created ASC'
        )
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getResult();

        $invoices = [];

        foreach ($rows as $row) {
            $status = $row['status'] ?? null;

            $invoices[] = [
                'invoiceId' => (string) ($row['invoiceId'] ?? ''),
                'client' => (string) ($row['client'] ?? '—'),
                'total' => $this->toMajor((string) ($row['total'] ?? '0')),
                'balance' => $this->toMajor((string) ($row['balance'] ?? '0')),
                'status' => $status instanceof BackedEnum ? (string) $status->value : (string) ($status ?? ''),
            ];
        }

        return $invoices;
    }

    /**
     * Minor units (integer string) -> major units string with 2 decimals.
     */
    private function toMajor(string $minor): string
    {
        if ($minor === '' || ! is_numeric($minor)) {
            return '0.00';
        }

        return (string) BigDecimal::of($minor)->dividedBy(100, 2, RoundingMode::HalfUp);
    }
}
