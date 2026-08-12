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

namespace SolidInvoice\CoreBundle\Receivables;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use SolidInvoice\ClientBundle\Entity\Client;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use function is_numeric;

/**
 * What each customer owes, in one place.
 *
 * The Debtors report and a customer's own page were each working this out their
 * own way, and they disagreed: the customer page only counted invoices sitting
 * at Pending, so an overdue invoice or a balance carried over from the old Tally
 * ledger simply did not show up there. Both now come through here, so the two
 * screens cannot drift apart again.
 *
 *     opening balance          carried over from the old Tally ledger
 *   + unpaid invoice balances  billed here and not settled
 *   - standalone receipts      money-in recorded against them off-invoice
 *   = closing balance          positive = they owe us, negative = we owe them
 *
 * Draft, cancelled and archived invoices are left out - they are not a debt.
 * Invoice figures are stored in MINOR units (fils) so they are divided by 100;
 * opening balances and receipts are already in major units.
 */
final readonly class ClientBalances
{
    public function __construct(
        private Connection $connection,
        private CompanySelector $companySelector,
    ) {
    }

    /**
     * Every customer of the current company, biggest debtor first.
     *
     * @return list<array{clientId: string, client: string, opening: string, invoiced: string, receipts: string, balance: string, balanceMinor: string, debit: string, credit: string}>
     */
    public function forCompany(): array
    {
        $companyId = $this->companySelector->getCompany();

        if ($companyId === null) {
            return [];
        }

        return $this->fetch($companyId->toBinary(), null);
    }

    /**
     * One customer, or null when they belong to another company.
     *
     * @return array{clientId: string, client: string, opening: string, invoiced: string, receipts: string, balance: string, balanceMinor: string, debit: string, credit: string}|null
     */
    public function forClient(Client $client): ?array
    {
        $companyId = $this->companySelector->getCompany();
        $clientId = $client->getId();

        if ($companyId === null || $clientId === null) {
            return null;
        }

        return $this->fetch($companyId->toBinary(), $clientId->toBinary())[0] ?? null;
    }

    /**
     * The adding up and the sorting are both done by the database in a single
     * pass, so this stays cheap however many customers there are.
     *
     * @return list<array{clientId: string, client: string, opening: string, invoiced: string, receipts: string, balance: string, balanceMinor: string, debit: string, credit: string}>
     */
    private function fetch(string $binaryCompanyId, ?string $binaryClientId): array
    {
        $params = [
            'invoiceCompanyId' => $binaryCompanyId,
            'receiptCompanyId' => $binaryCompanyId,
            'clientCompanyId' => $binaryCompanyId,
        ];

        $types = [
            'invoiceCompanyId' => ParameterType::BINARY,
            'receiptCompanyId' => ParameterType::BINARY,
            'clientCompanyId' => ParameterType::BINARY,
        ];

        $clientCondition = '';

        if ($binaryClientId !== null) {
            $clientCondition = ' AND c.id = :clientId';
            $params['clientId'] = $binaryClientId;
            $types['clientId'] = ParameterType::BINARY;
        }

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
               AND (c.archived IS NULL OR c.archived = 0)" . $clientCondition . '
             ORDER BY balance DESC',
            // The same company three times under three names: a named
            // placeholder repeated in one statement is not portable across
            // drivers, and this must not be the thing that breaks the report.
            $params,
            $types
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
                // Minor units, for the templates that hand the figure to
                // formatCurrency - which takes fils, not dirhams.
                'balanceMinor' => (string) $balance->withPointMovedRight(2)->toBigInteger(),
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
}
