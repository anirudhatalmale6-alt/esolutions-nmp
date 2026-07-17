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
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;
use function ctype_xdigit;
use function hex2bin;
use function strlen;
use function trim;

/**
 * "Sales by Client" report. The sibling of "Sales by Model": instead of grouping
 * the invoice line items by product, it groups them by the customer, so the user
 * can see - per client - how much they have bought, how many invoices, their total
 * spend and average invoice, and when they first/last purchased. Selecting a client
 * drills into the exact models they buy and their full purchase history, which is
 * useful for spotting the best buyers and targeting future offers.
 *
 * Like Sales by Model it runs raw DBAL SQL on the real tables, scoped to the active
 * company by its binary id, with archived invoices excluded. Line amounts are stored
 * in MINOR units (fils/cents), so every money value is divided by 100 for display.
 * The client is addressed in the URL by the lowercase hex of its binary ULID id.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class SalesByClient
{
    public function __construct(
        private Connection $connection,
        private CompanySelector $companySelector,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[Template('@SolidInvoiceCore/Report/sales_by_client.html.twig')]
    public function __invoke(Request $request): array
    {
        $companyId = $this->companySelector->getCompany();
        $binaryCompanyId = $companyId?->toBinary();

        if ($binaryCompanyId === null) {
            return ['detail' => false, 'client' => null, 'clientId' => null, 'clients' => [], 'models' => [], 'history' => []];
        }

        $clientHex = trim((string) $request->query->get('client', ''));

        if (strlen($clientHex) === 32 && ctype_xdigit($clientHex)) {
            $binaryClientId = (string) hex2bin($clientHex);
            $name = $this->clientName($binaryCompanyId, $binaryClientId);

            if ($name !== null) {
                return [
                    'detail' => true,
                    'client' => $name,
                    'clientId' => $clientHex,
                    'models' => $this->clientModels($binaryCompanyId, $binaryClientId),
                    'history' => $this->clientHistory($binaryCompanyId, $binaryClientId),
                    'clients' => [],
                ];
            }
        }

        return [
            'detail' => false,
            'client' => null,
            'clientId' => null,
            'clients' => $this->clientSummary($binaryCompanyId),
            'models' => [],
            'history' => [],
        ];
    }

    /**
     * Resolve a client's display name, but only if it actually has sales in the
     * active company (keeps the report scoped to real buyers).
     */
    private function clientName(string $binaryCompanyId, string $binaryClientId): ?string
    {
        $name = $this->connection->executeQuery(
            'SELECT c.name
             FROM clients c
             INNER JOIN invoices i ON i.client_id = c.id
             INNER JOIN invoice_lines il ON il.invoice_id = i.id
             WHERE c.id = :clientId
               AND il.company_id = :companyId
             LIMIT 1',
            ['clientId' => $binaryClientId, 'companyId' => $binaryCompanyId],
            ['clientId' => ParameterType::BINARY, 'companyId' => ParameterType::BINARY]
        )->fetchOne();

        return $name === false ? null : (string) $name;
    }

    /**
     * One row per client, sorted by total spend (highest first).
     *
     * @return list<array{clientId: string, client: string, invoices: int, units: string, revenue: string, avgInvoice: string, firstPurchase: ?DateTimeImmutable, lastPurchase: ?DateTimeImmutable}>
     */
    private function clientSummary(string $binaryCompanyId): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT LOWER(HEX(c.id)) AS clientId,
                    c.name AS client,
                    COUNT(DISTINCT i.id) AS invoices,
                    SUM(il.qty) AS units,
                    SUM(il.total_amount) AS revenue,
                    MIN(i.invoice_date) AS firstPurchase,
                    MAX(i.invoice_date) AS lastPurchase
             FROM invoice_lines il
             INNER JOIN invoices i ON i.id = il.invoice_id
             INNER JOIN clients c ON c.id = i.client_id
             WHERE il.company_id = :companyId
               AND (i.archived IS NULL OR i.archived = 0)
             GROUP BY c.id, c.name',
            ['companyId' => $binaryCompanyId],
            ['companyId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        $clients = [];

        foreach ($rows as $row) {
            $revenueMinor = (string) ($row['revenue'] ?? '0');
            $invoices = (int) ($row['invoices'] ?? 0);

            $clients[] = [
                'clientId' => (string) ($row['clientId'] ?? ''),
                'client' => (string) $row['client'],
                'invoices' => $invoices,
                'units' => $this->trimQty((string) ($row['units'] ?? '0')),
                'revenue' => $this->toMajor($revenueMinor),
                'avgInvoice' => $this->avgPerInvoice($revenueMinor, $invoices),
                'firstPurchase' => $this->toDate((string) ($row['firstPurchase'] ?? '')),
                'lastPurchase' => $this->toDate((string) ($row['lastPurchase'] ?? '')),
            ];
        }

        usort($clients, static fn (array $a, array $b): int => BigDecimal::of($b['revenue'])->compareTo(BigDecimal::of($a['revenue'])));

        return $clients;
    }

    /**
     * The models a given client buys, most revenue first.
     *
     * @return list<array{model: string, units: string, invoices: int, minPrice: string, maxPrice: string, avgPrice: string, revenue: string}>
     */
    private function clientModels(string $binaryCompanyId, string $binaryClientId): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT il.description AS model,
                    SUM(il.qty) AS units,
                    COUNT(DISTINCT il.invoice_id) AS invoices,
                    MIN(il.price_amount) AS minPrice,
                    MAX(il.price_amount) AS maxPrice,
                    SUM(il.total_amount) AS revenue
             FROM invoice_lines il
             INNER JOIN invoices i ON i.id = il.invoice_id
             WHERE il.company_id = :companyId
               AND i.client_id = :clientId
               AND (i.archived IS NULL OR i.archived = 0)
             GROUP BY il.description',
            ['companyId' => $binaryCompanyId, 'clientId' => $binaryClientId],
            ['companyId' => ParameterType::BINARY, 'clientId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        $models = [];

        foreach ($rows as $row) {
            $units = (string) ($row['units'] ?? '0');
            $revenueMinor = (string) ($row['revenue'] ?? '0');

            $models[] = [
                'model' => (string) $row['model'],
                'units' => $this->trimQty($units),
                'invoices' => (int) ($row['invoices'] ?? 0),
                'minPrice' => $this->toMajor((string) ($row['minPrice'] ?? '0')),
                'maxPrice' => $this->toMajor((string) ($row['maxPrice'] ?? '0')),
                'avgPrice' => $this->avgUnitPrice($revenueMinor, $units),
                'revenue' => $this->toMajor($revenueMinor),
            ];
        }

        usort($models, static fn (array $a, array $b): int => BigDecimal::of($b['revenue'])->compareTo(BigDecimal::of($a['revenue'])));

        return $models;
    }

    /**
     * Every individual purchase line of a given client, newest first.
     *
     * @return list<array{invoiceId: string, date: ?DateTimeImmutable, model: string, qty: string, price: string, total: string}>
     */
    private function clientHistory(string $binaryCompanyId, string $binaryClientId): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT i.invoice_id AS invoiceId,
                    i.invoice_date AS saleDate,
                    il.description AS model,
                    il.qty AS qty,
                    il.price_amount AS price,
                    il.total_amount AS total
             FROM invoice_lines il
             INNER JOIN invoices i ON i.id = il.invoice_id
             WHERE il.company_id = :companyId
               AND i.client_id = :clientId
               AND (i.archived IS NULL OR i.archived = 0)
             ORDER BY i.invoice_date DESC',
            ['companyId' => $binaryCompanyId, 'clientId' => $binaryClientId],
            ['companyId' => ParameterType::BINARY, 'clientId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        $history = [];

        foreach ($rows as $row) {
            $history[] = [
                'invoiceId' => (string) ($row['invoiceId'] ?? ''),
                'date' => $this->toDate((string) ($row['saleDate'] ?? '')),
                'model' => (string) ($row['model'] ?? ''),
                'qty' => $this->trimQty((string) ($row['qty'] ?? '0')),
                'price' => $this->toMajor((string) ($row['price'] ?? '0')),
                'total' => $this->toMajor((string) ($row['total'] ?? '0')),
            ];
        }

        return $history;
    }

    private function toDate(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
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
     * Weighted average selling price per unit (revenue / units), in major units.
     */
    private function avgUnitPrice(string $revenueMinor, string $units): string
    {
        if ($units === '' || ! is_numeric($units) || BigDecimal::of($units)->isZero()) {
            return '0.00';
        }

        return (string) BigDecimal::of($revenueMinor)
            ->dividedBy(BigDecimal::of($units), 2, RoundingMode::HalfUp)
            ->dividedBy(100, 2, RoundingMode::HalfUp);
    }

    /**
     * Average value per invoice (revenue / invoice count), in major units.
     */
    private function avgPerInvoice(string $revenueMinor, int $invoices): string
    {
        if ($invoices <= 0) {
            return '0.00';
        }

        return (string) BigDecimal::of($revenueMinor)
            ->dividedBy($invoices, 2, RoundingMode::HalfUp)
            ->dividedBy(100, 2, RoundingMode::HalfUp);
    }

    /**
     * Drop a trailing ".0"/".00" from a quantity so "5.00" shows as "5".
     */
    private function trimQty(string $qty): string
    {
        if (! is_numeric($qty)) {
            return '0';
        }

        // Normalise to a plain decimal string and drop any trailing zeros so
        // "5.00" shows as "5" and "5.50" as "5.5". Done with string ops rather
        // than BigDecimal::stripTrailingZeros(), which is absent in the
        // brick/math version installed here.
        $qty = (string) $qty;

        if (str_contains($qty, '.')) {
            $qty = rtrim(rtrim($qty, '0'), '.');
        }

        return $qty === '' || $qty === '-0' ? '0' : $qty;
    }
}
