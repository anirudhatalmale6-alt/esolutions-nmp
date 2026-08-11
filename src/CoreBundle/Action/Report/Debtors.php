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
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function array_filter;
use function count;
use function is_numeric;
use function usort;

/**
 * Debtors / Receivables - the "who owes us what" report, laid out like the
 * Sundry Debtors group summary the accountant already works from: one line per
 * customer, a Debit column for what they owe and a Credit column for what we owe
 * them, and a grand total at the foot.
 *
 * Each customer's closing balance is built from three pieces:
 *
 *     opening balance          carried over from the old Tally ledger
 *   + unpaid invoice balances  what they have been billed here and not settled
 *   - standalone receipts      money-in recorded against them off-invoice
 *   = closing balance          positive = they owe us, negative = we owe them
 *
 * Draft, cancelled and archived invoices are left out - they are not a debt.
 * Invoice figures are stored in MINOR units (fils) so they are divided by 100;
 * opening balances and receipts are already in major units.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class Debtors
{
    public function __construct(
        private Connection $connection,
        private CompanySelector $companySelector,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[Template('@SolidInvoiceCore/Report/debtors.html.twig')]
    public function __invoke(Request $request): array
    {
        $companyId = $this->companySelector->getCompany();
        $binaryCompanyId = $companyId?->toBinary();

        if ($binaryCompanyId === null) {
            return $this->empty();
        }

        // Show the settled customers only when asked - the accountant's working
        // view is the list of people who still owe something.
        $showSettled = $request->query->getBoolean('all');

        $rows = $this->buildRows($binaryCompanyId);

        $totalDebit = BigDecimal::zero();
        $totalCredit = BigDecimal::zero();
        $visible = [];

        foreach ($rows as $row) {
            $balance = BigDecimal::of($row['balance']);

            if ($balance->isPositive()) {
                $totalDebit = $totalDebit->plus($balance);
            } elseif ($balance->isNegative()) {
                $totalCredit = $totalCredit->plus($balance->abs());
            }

            if ($showSettled || ! $balance->isZero()) {
                $visible[] = $row;
            }
        }

        usort(
            $visible,
            static fn (array $a, array $b): int => BigDecimal::of($b['balance'])->compareTo(BigDecimal::of($a['balance']))
        );

        return [
            'rows' => $visible,
            'totalDebit' => (string) $totalDebit->toScale(2),
            'totalCredit' => (string) $totalCredit->toScale(2),
            'net' => (string) $totalDebit->minus($totalCredit)->toScale(2),
            'showSettled' => $showSettled,
            'settledCount' => count($rows) - count(array_filter($rows, static fn (array $r): bool => ! BigDecimal::of($r['balance'])->isZero())),
        ];
    }

    /**
     * One row per customer: opening balance, what is still open on their
     * invoices, what they have paid off-invoice, and the resulting balance.
     *
     * @return list<array{clientId: string, client: string, opening: string, invoiced: string, receipts: string, balance: string, debit: string, credit: string}>
     */
    private function buildRows(string $binaryCompanyId): array
    {
        $clients = $this->connection->executeQuery(
            'SELECT LOWER(HEX(c.id)) AS clientId,
                    c.name AS name,
                    c.opening_balance AS opening
             FROM clients c
             WHERE c.company_id = :companyId
               AND (c.archived IS NULL OR c.archived = 0)',
            ['companyId' => $binaryCompanyId],
            ['companyId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        $outstanding = $this->outstandingByClient($binaryCompanyId);
        $receipts = $this->receiptsByClient($binaryCompanyId);

        $rows = [];

        foreach ($clients as $client) {
            $id = (string) $client['clientId'];

            $opening = $this->amount((string) ($client['opening'] ?? '0'));
            $invoiced = BigDecimal::of($outstanding[$id] ?? '0.00');
            $paid = BigDecimal::of($receipts[$id] ?? '0.00');

            $balance = $opening->plus($invoiced)->minus($paid);

            $rows[] = [
                'clientId' => $id,
                'client' => (string) $client['name'],
                'opening' => (string) $opening->toScale(2),
                'invoiced' => (string) $invoiced->toScale(2),
                'receipts' => (string) $paid->toScale(2),
                'balance' => (string) $balance->toScale(2),
                // Split into the accountant's two columns up front, so the
                // template never has to reason about the sign.
                'debit' => $balance->isPositive() ? (string) $balance->toScale(2) : '',
                'credit' => $balance->isNegative() ? (string) $balance->abs()->toScale(2) : '',
            ];
        }

        return $rows;
    }

    /**
     * Unpaid balance still open on each customer's issued invoices, in major
     * units. Draft / cancelled / archived invoices are not a debt.
     *
     * @return array<string, string>
     */
    private function outstandingByClient(string $binaryCompanyId): array
    {
        $rows = $this->connection->executeQuery(
            "SELECT LOWER(HEX(i.client_id)) AS clientId,
                    SUM(i.balance_amount) AS balance
             FROM invoices i
             WHERE i.company_id = :companyId
               AND (i.archived IS NULL OR i.archived = 0)
               AND i.status NOT IN ('draft', 'cancelled', 'archived')
             GROUP BY i.client_id",
            ['companyId' => $binaryCompanyId],
            ['companyId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        $totals = [];

        foreach ($rows as $row) {
            $minor = (string) ($row['balance'] ?? '0');
            $totals[(string) $row['clientId']] = is_numeric($minor)
                ? (string) BigDecimal::of($minor)->dividedBy(100, 2, RoundingMode::HalfUp)
                : '0.00';
        }

        return $totals;
    }

    /**
     * Standalone money-in receipts recorded against each customer (major units).
     *
     * @return array<string, string>
     */
    private function receiptsByClient(string $binaryCompanyId): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT LOWER(HEX(r.client_id)) AS clientId,
                    SUM(r.amount) AS total
             FROM customer_receipt r
             WHERE r.company_id = :companyId
               AND r.client_id IS NOT NULL
             GROUP BY r.client_id',
            ['companyId' => $binaryCompanyId],
            ['companyId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) $row['clientId']] = (string) $this->amount((string) ($row['total'] ?? '0'))->toScale(2);
        }

        return $totals;
    }

    private function amount(string $value): BigDecimal
    {
        return is_numeric($value) ? BigDecimal::of($value) : BigDecimal::zero();
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(): array
    {
        return [
            'rows' => [],
            'totalDebit' => '0.00',
            'totalCredit' => '0.00',
            'net' => '0.00',
            'showSettled' => false,
            'settledCount' => 0,
        ];
    }
}
