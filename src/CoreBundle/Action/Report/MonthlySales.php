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
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;
use function is_numeric;

/**
 * One-click "close the month" sales report. For a chosen month (current month by
 * default) it lists every invoice raised in that month on a few clean, printable
 * pages, with totals for billed / received / outstanding. Built so the office can
 * keep a monthly paper trail without printing every single invoice.
 *
 * Invoice figures are stored in MINOR units (fils/cents) and divided by 100.
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

        $totalBilled = BigDecimal::zero();
        $totalOutstanding = BigDecimal::zero();

        foreach ($invoices as $invoice) {
            $totalBilled = $totalBilled->plus(BigDecimal::of($invoice['total']));
            $totalOutstanding = $totalOutstanding->plus(BigDecimal::of($invoice['balance']));
        }

        $totalReceived = $totalBilled->minus($totalOutstanding);

        if ($totalReceived->isNegative()) {
            $totalReceived = BigDecimal::zero();
        }

        return [
            'month' => $month,
            'thisMonth' => (new DateTimeImmutable('today'))->format('Y-m'),
            'invoices' => $invoices,
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
     * @return list<array{invoiceId: string, client: string, date: ?\DateTimeInterface, total: string, paid: string, balance: string, status: string}>
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
