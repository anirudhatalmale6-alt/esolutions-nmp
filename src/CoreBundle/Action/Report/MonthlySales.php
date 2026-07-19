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
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\InvoiceBundle\Entity\Line;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;
use function is_numeric;
use function rtrim;
use function str_contains;

/**
 * One-click "close the month" sales report. For a chosen month (current month by
 * default) it lists, day by day, every sale made that month with the actual items
 * sold, to whom, for how much — like a running log of invoices — on clean,
 * printable pages, styled to match the Daily Ledger.
 *
 * Invoice and line figures are stored in MINOR units (fils/cents) and divided by
 * 100. Data is auto-scoped to the viewer's company by Doctrine's company filter.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class MonthlySales
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[Template('@SolidInvoiceCore/Report/monthly_sales.html.twig')]
    public function __invoke(Request $request): array
    {
        $month = $this->resolveMonth((string) $request->query->get('month', ''));
        $start = new DateTimeImmutable($month->format('Y-m-01') . ' 00:00:00');
        $end = new DateTimeImmutable($start->format('Y-m-t') . ' 23:59:59');

        $invoices = $this->invoicesForMonth($start, $end);
        $linesByInvoice = $this->linesForMonth($start, $end);

        $totalBilled = BigDecimal::zero();
        $totalOutstanding = BigDecimal::zero();

        // Group the month's invoices by the day they were raised, and hang each
        // invoice's sold items underneath it, so the report reads day-by-day.
        $days = [];

        foreach ($invoices as $invoice) {
            $totalBilled = $totalBilled->plus(BigDecimal::of($invoice['total']));
            $totalOutstanding = $totalOutstanding->plus(BigDecimal::of($invoice['balance']));

            $date = $invoice['date'];
            $dayKey = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : 'unknown';

            if (! isset($days[$dayKey])) {
                $days[$dayKey] = [
                    'date' => $date,
                    'sales' => [],
                    'dayTotal' => BigDecimal::zero(),
                ];
            }

            $invoice['lines'] = $linesByInvoice[$invoice['invoiceId']] ?? [];
            $days[$dayKey]['sales'][] = $invoice;
            $days[$dayKey]['dayTotal'] = $days[$dayKey]['dayTotal']->plus(BigDecimal::of($invoice['total']));
        }

        // Freeze the day totals to display strings.
        $days = array_values($days);
        foreach ($days as &$day) {
            $day['dayTotal'] = (string) $day['dayTotal']->toScale(2);
        }
        unset($day);

        $totalReceived = $totalBilled->minus($totalOutstanding);

        if ($totalReceived->isNegative()) {
            $totalReceived = BigDecimal::zero();
        }

        return [
            'month' => $month,
            'thisMonth' => (new DateTimeImmutable('today'))->format('Y-m'),
            'days' => $days,
            'invoiceCount' => count($invoices),
            'totalBilled' => (string) $totalBilled->toScale(2),
            'totalReceived' => (string) $totalReceived->toScale(2),
            'totalOutstanding' => (string) $totalOutstanding->toScale(2),
        ];
    }

    private function resolveMonth(string $raw): DateTimeImmutable
    {
        if ($raw !== '') {
            try {
                // Accept a plain "YYYY-MM" from the month picker.
                return new DateTimeImmutable($raw . '-01');
            } catch (Throwable) {
                // fall through to the current month
            }
        }

        return new DateTimeImmutable('first day of this month');
    }

    /**
     * Invoices raised within the month, one row each, ordered by date.
     *
     * @return list<array{invoiceId: string, client: string, date: ?DateTimeInterface, total: string, paid: string, balance: string, status: string}>
     */
    private function invoicesForMonth(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $rows = $this->entityManager->createQuery(
            'SELECT inv.invoiceId AS invoiceId, inv.invoiceDate AS invoiceDate, inv.total AS total,
                    inv.balance AS balance, inv.status AS status, c.name AS client
             FROM ' . Invoice::class . ' inv
             JOIN inv.client c
             WHERE inv.invoiceDate BETWEEN :start AND :end
             ORDER BY inv.invoiceDate ASC, inv.created ASC'
        )
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getResult();

        $invoices = [];

        foreach ($rows as $row) {
            $status = $row['status'] ?? null;
            $total = $this->toMajor((string) ($row['total'] ?? '0'));
            $balance = $this->toMajor((string) ($row['balance'] ?? '0'));
            $paid = (string) BigDecimal::of($total)->minus(BigDecimal::of($balance))->toScale(2);

            $invoices[] = [
                'invoiceId' => (string) ($row['invoiceId'] ?? ''),
                'client' => (string) ($row['client'] ?? '—'),
                'date' => $row['invoiceDate'] ?? null,
                'total' => $total,
                'paid' => $paid,
                'balance' => $balance,
                'status' => $status instanceof BackedEnum ? (string) $status->value : (string) ($status ?? ''),
            ];
        }

        return $invoices;
    }

    /**
     * Sold line items for the month, grouped by invoice number.
     *
     * @return array<string, list<array{description: string, qty: string, unitPrice: string, amount: string}>>
     */
    private function linesForMonth(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $rows = $this->entityManager->createQuery(
            'SELECT inv.invoiceId AS invoiceId, l.description AS description, l.qty AS qty,
                    l.price AS price, l.total AS lineTotal
             FROM ' . Line::class . ' l
             JOIN l.invoice inv
             WHERE inv.invoiceDate BETWEEN :start AND :end
             ORDER BY inv.invoiceDate ASC, inv.created ASC'
        )
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getResult();

        $linesByInvoice = [];

        foreach ($rows as $row) {
            $invoiceId = (string) ($row['invoiceId'] ?? '');

            $linesByInvoice[$invoiceId][] = [
                'description' => (string) ($row['description'] ?? ''),
                'qty' => $this->trimQty((string) ($row['qty'] ?? '0')),
                'unitPrice' => $this->toMajor((string) ($row['price'] ?? '0')),
                'amount' => $this->toMajor((string) ($row['lineTotal'] ?? '0')),
            ];
        }

        return $linesByInvoice;
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

    /**
     * Drop trailing zeros/decimal point from a quantity so "9" shows as "9" and
     * "1.5" stays "1.5" (version-independent, no BigDecimal::stripTrailingZeros
     * which this host's brick/math lacks).
     */
    private function trimQty(string $qty): string
    {
        if ($qty === '' || ! is_numeric($qty)) {
            return '0';
        }

        if (str_contains($qty, '.')) {
            $qty = rtrim(rtrim($qty, '0'), '.');
        }

        return $qty === '' ? '0' : $qty;
    }
}
