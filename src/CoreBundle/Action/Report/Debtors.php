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
use function is_numeric;

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
        $settledCount = 0;
        $visible = [];

        // The rows arrive already sorted by balance, biggest debtor first.
        foreach ($rows as $row) {
            $balance = BigDecimal::of($row['balance']);

            if ($balance->isPositive()) {
                $totalDebit = $totalDebit->plus($balance);
            } elseif ($balance->isNegative()) {
                $totalCredit = $totalCredit->plus($balance->abs());
            } else {
                ++$settledCount;
            }

            if ($showSettled || ! $balance->isZero()) {
                $visible[] = $row;
            }
        }

        return [
            'rows' => $visible,
            'totalDebit' => (string) $totalDebit->toScale(2),
            'totalCredit' => (string) $totalCredit->toScale(2),
            'net' => (string) $totalDebit->minus($totalCredit)->toScale(2),
            'showSettled' => $showSettled,
            'settledCount' => $settledCount,
        ];
    }

    /**
     * One row per customer: opening balance, what is still open on their
     * invoices, what they have paid off-invoice, and the resulting balance.
     *
     * The adding up and the sorting are both done by the database in a single
     * pass. An earlier version pulled three result sets back and combined them
     * in PHP, which meant a BigDecimal per customer per comparison while
     * sorting - fine for a handful of customers, and far too slow once the old
     * Tally ledger brought several thousand of them across.
     *
     * Invoice figures are stored in MINOR units (fils) hence the /100; opening
     * balances and receipts are already in major units. Draft, cancelled and
     * archived invoices are not a debt and are left out.
     *
     * @return list<array{clientId: string, client: string, opening: string, invoiced: string, receipts: string, balance: string, debit: string, credit: string}>
     */
    private function buildRows(string $binaryCompanyId): array
    {
        $rows = $this->connection->executeQuery(
            "SELECT LOWER(HEX(c.id)) AS clientId,
                    c.name AS name,
                    CAST(c.opening_balance AS DECIMAL(18,2)) AS opening,
                    ROUND(COALESCE(inv.invoiced_minor, 0) / 100, 2) AS invoiced,
                    ROUND(COALESCE(rec.receipts_major, 0), 2) AS receipts,
                    ROUND(
                        c.opening_balance
                        + COALESCE(inv.invoiced_minor, 0) / 100
                        - COALESCE(rec.receipts_major, 0)
                    , 2) AS balance
             FROM clients c
             LEFT JOIN (
                 SELECT i.client_id, SUM(i.balance_amount) AS invoiced_minor
                 FROM invoices i
                 WHERE i.company_id = :invoiceCompanyId
                   AND (i.archived IS NULL OR i.archived = 0)
                   AND i.status NOT IN ('draft', 'cancelled', 'archived')
                 GROUP BY i.client_id
             ) inv ON inv.client_id = c.id
             LEFT JOIN (
                 SELECT r.client_id, SUM(r.amount) AS receipts_major
                 FROM customer_receipt r
                 WHERE r.company_id = :receiptCompanyId
                   AND r.client_id IS NOT NULL
                 GROUP BY r.client_id
             ) rec ON rec.client_id = c.id
             WHERE c.company_id = :clientCompanyId
               AND (c.archived IS NULL OR c.archived = 0)
             ORDER BY balance DESC",
            // The same company three times under three names: a named
            // placeholder repeated in one statement is not portable across
            // drivers, and this must not be the thing that breaks the report.
            [
                'invoiceCompanyId' => $binaryCompanyId,
                'receiptCompanyId' => $binaryCompanyId,
                'clientCompanyId' => $binaryCompanyId,
            ],
            [
                'invoiceCompanyId' => ParameterType::BINARY,
                'receiptCompanyId' => ParameterType::BINARY,
                'clientCompanyId' => ParameterType::BINARY,
            ]
        )->fetchAllAssociative();

        $out = [];

        foreach ($rows as $row) {
            $balance = $this->amount((string) ($row['balance'] ?? '0'));

            $out[] = [
                'clientId' => (string) $row['clientId'],
                'client' => (string) $row['name'],
                'opening' => $this->money((string) ($row['opening'] ?? '0')),
                'invoiced' => $this->money((string) ($row['invoiced'] ?? '0')),
                'receipts' => $this->money((string) ($row['receipts'] ?? '0')),
                'balance' => (string) $balance->toScale(2),
                // Split into the accountant's two columns up front, so the
                // template never has to reason about the sign.
                'debit' => $balance->isPositive() ? (string) $balance->toScale(2) : '',
                'credit' => $balance->isNegative() ? (string) $balance->abs()->toScale(2) : '',
            ];
        }

        return $out;
    }

    private function money(string $value): string
    {
        return (string) $this->amount($value)->toScale(2, RoundingMode::HalfUp);
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
