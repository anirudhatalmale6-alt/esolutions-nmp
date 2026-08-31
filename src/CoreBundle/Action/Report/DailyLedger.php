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

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Repository\CreditNoteRepository;
use SolidInvoice\CoreBundle\Repository\CustomerReceiptRepository;
use SolidInvoice\CoreBundle\Repository\DailyNoteRepository;
use SolidInvoice\CoreBundle\Repository\ExpenseRepository;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\InvoiceBundle\Enum\InvoiceStatus;
use SolidInvoice\InvoiceBundle\Twig\Extension\InvoiceTemplateExtension;
use SolidInvoice\PaymentBundle\Entity\Payment;
use SolidInvoice\PaymentBundle\Enum\PaymentStatus;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;
use function count;
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
        private CustomerReceiptRepository $receiptRepository,
        private DailyNoteRepository $noteRepository,
        private InvoiceTemplateExtension $invoiceTemplateExtension,
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
        $receipts = $this->receiptRepository->findBetween($start, $end);
        $suppliers = $this->supplierPayments($start, $end);
        $expenses = $this->expenseRepository->findBetween($start, $end);
        $refunds = $this->creditNoteRepository->findCashBetween($start, $end);
        $invoices = $this->invoicesRaised($start, $end);
        $purchases = $this->purchasesRaised($start, $end);

        $moneyIn = BigDecimal::zero();
        foreach ($payments as $payment) {
            $moneyIn = $moneyIn->plus(BigDecimal::of($payment['amount']));
        }

        // Standalone customer payments (debtors clearing balances / counter cash)
        // are money in too - the whole point of recording them here instead of on
        // paper is that they land in the day's cash.
        $receiptsIn = BigDecimal::zero();
        foreach ($receipts as $receipt) {
            $receiptsIn = $receiptsIn->plus(BigDecimal::of($receipt->getAmount()));
        }
        $moneyIn = $moneyIn->plus($receiptsIn);

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

        // A cancelled invoice was voided - it is not money billed and its balance
        // is not owed - so it is left out of the day's billed total and counted
        // separately. It still gets listed (marked Cancelled) so the day is complete.
        $invoicesTotal = BigDecimal::zero();
        $invoicesPaid = BigDecimal::zero();
        $billedCount = 0;
        $cancelledCount = 0;
        foreach ($invoices as $invoice) {
            if (($invoice['status'] ?? '') === InvoiceStatus::Cancelled->value) {
                ++$cancelledCount;
                continue;
            }

            ++$billedCount;
            $invoicesTotal = $invoicesTotal->plus(BigDecimal::of($invoice['total']));
            $invoicesPaid = $invoicesPaid->plus(BigDecimal::of($invoice['paid']));
        }

        // Purchases raised today are the buying-side mirror of "invoices raised
        // today": what was bought on the day, whether or not it was paid for.
        // They are deliberately NOT part of moneyOut - a purchase taken on
        // credit moves no cash, and adding it would make the day's net cash
        // wrong. The unpaid part is totalled separately as credit taken.
        $purchasesTotal = BigDecimal::zero();
        $purchasesPaid = BigDecimal::zero();
        $purchasesBalance = BigDecimal::zero();
        foreach ($purchases as $purchase) {
            $purchasesTotal = $purchasesTotal->plus(BigDecimal::of($purchase['total']));
            $purchasesPaid = $purchasesPaid->plus(BigDecimal::of($purchase['paid']));
            $purchasesBalance = $purchasesBalance->plus(BigDecimal::of($purchase['balance']));
        }

        $moneyOut = $supplierOut->plus($expensesOut)->plus($refundsOut);
        $net = $moneyIn->minus($moneyOut);

        return [
            'date' => $date,
            'today' => (new DateTimeImmutable('today'))->format('Y-m-d'),
            // The day's scrap note - the paper pad, kept with the day it belongs to.
            'note' => $this->noteRepository->findForDate($date),
            'recentNotes' => $this->noteRepository->findRecent(15),
            'payments' => $payments,
            'receipts' => $receipts,
            'receiptsIn' => (string) $receiptsIn->toScale(2),
            'suppliers' => $suppliers,
            'expenses' => $expenses,
            'refunds' => $refunds,
            'invoices' => $invoices,
            'purchases' => $purchases,
            'purchasesTotal' => (string) $purchasesTotal->toScale(2),
            'purchasesPaid' => (string) $purchasesPaid->toScale(2),
            'purchasesBalance' => (string) $purchasesBalance->toScale(2),
            'purchasesCount' => count($purchases),
            'billedCount' => $billedCount,
            'cancelledCount' => $cancelledCount,
            'moneyIn' => (string) $moneyIn->toScale(2),
            'supplierOut' => (string) $supplierOut->toScale(2),
            'expensesOut' => (string) $expensesOut->toScale(2),
            'refundsOut' => (string) $refundsOut->toScale(2),
            'moneyOut' => (string) $moneyOut->toScale(2),
            'invoicesTotal' => (string) $invoicesTotal->toScale(2),
            'invoicesPaid' => (string) $invoicesPaid->toScale(2),
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
     * Purchases (supplier bills) raised within the day, whether paid or not.
     *
     * This is the buying-side mirror of "invoices raised today". A purchase
     * order taken on credit pays nothing on the day, so it never appears in
     * supplier payments - which made a day's buying invisible on the ledger
     * even though the stock and the payable both existed. Amounts are stored in
     * major units on Purchase, so no conversion is needed.
     *
     * @return list<array{supplier: string, reference: string, description: string, total: string, paid: string, balance: string}>
     */
    private function purchasesRaised(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $rows = $this->entityManager->createQuery(
            'SELECT c.name AS supplier, pu.reference AS reference, pu.description AS description,
                    pu.totalAmount AS total, pu.amountPaid AS paid
             FROM SolidInvoice\CoreBundle\Entity\Purchase pu
             JOIN pu.client c
             WHERE pu.purchaseDate BETWEEN :start AND :end
             ORDER BY pu.created ASC'
        )
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getResult();

        $purchases = [];

        foreach ($rows as $row) {
            $total = BigDecimal::of((string) ($row['total'] ?? '0'));
            $paid = BigDecimal::of((string) ($row['paid'] ?? '0'));
            $balance = $total->minus($paid);

            // An overpaid purchase should not report a negative amount owed.
            if ($balance->isNegative()) {
                $balance = BigDecimal::zero();
            }

            $purchases[] = [
                'supplier' => (string) ($row['supplier'] ?? '—'),
                'reference' => (string) ($row['reference'] ?? ''),
                'description' => trim((string) ($row['description'] ?? '')),
                'total' => (string) $total->toScale(2),
                'paid' => (string) $paid->toScale(2),
                'balance' => (string) $balance->toScale(2),
            ];
        }

        return $purchases;
    }

    /**
     * Invoices raised within the day (count + total billed), total major units.
     *
     * @return list<array{invoiceId: string, client: string, total: string, status: string}>
     */
    private function invoicesRaised(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        // Fetch the full Invoice entities (not scalars) so the ledger can show
        // the exact same payment-aware status as the invoice list: an invoice
        // with a deposit reads "Partially Paid", not "Pending". The paid/balance
        // figures are taken from captured payments too (the stored balance stays
        // 0 on a not-yet-activated invoice, so it cannot be trusted for a pending
        // invoice that already has a deposit).
        $rows = $this->entityManager->createQuery(
            'SELECT inv
             FROM ' . Invoice::class . ' inv
             JOIN inv.client c
             WHERE inv.invoiceDate BETWEEN :start AND :end
             ORDER BY inv.created ASC'
        )
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getResult();

        $invoices = [];

        foreach ($rows as $invoice) {
            $statusEnum = $invoice->getStatus();
            $view = $this->invoiceTemplateExtension->invoiceStatusView($invoice);
            $payment = $this->invoiceTemplateExtension->paymentStatus($invoice);

            $total = $this->toMajor((string) $invoice->getTotal());
            $paid = $this->toMajor((string) $payment['paid']);

            // Balance from captured payments, floored at zero (an overpayment
            // should not show a negative amount owed).
            $balanceMinor = BigDecimal::of((string) $payment['balance']);
            if ($balanceMinor->isNegative()) {
                $balanceMinor = BigDecimal::zero();
            }
            $balance = $this->toMajor((string) $balanceMinor);

            $invoices[] = [
                'invoiceId' => (string) $invoice->getInvoiceId(),
                'client' => (string) ($invoice->getClient()?->getName() ?? '—'),
                'total' => $total,
                'balance' => $balance,
                'paid' => $paid,
                'status' => $statusEnum instanceof InvoiceStatus ? $statusEnum->value : '',
                'statusLabel' => $view->name,
                'statusColor' => $view->color,
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
