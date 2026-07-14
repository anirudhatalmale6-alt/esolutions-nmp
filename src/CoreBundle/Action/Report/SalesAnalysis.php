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
use function trim;

/**
 * "Selling price by model" report. Reads the line items off every (non-archived)
 * invoice and groups them by product description so the user can see, per model:
 * how many units were sold, the price range and average selling price, and - when
 * a model is selected - the full sale history and the top buyers for it.
 *
 * Runs through raw DBAL SQL on the real tables (rather than DQL aggregates over
 * the single-table-inheritance Line entity), scoping to the active company by its
 * binary id. Line amounts are stored in MINOR units (fils/cents), so every money
 * value is divided by 100 for display.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class SalesAnalysis
{
    public function __construct(
        private Connection $connection,
        private CompanySelector $companySelector,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[Template('@SolidInvoiceCore/Report/sales_analysis.html.twig')]
    public function __invoke(Request $request): array
    {
        $companyId = $this->companySelector->getCompany();
        $binaryCompanyId = $companyId?->toBinary();

        $model = trim((string) $request->query->get('model', ''));

        if ($binaryCompanyId === null) {
            return ['model' => $model !== '' ? $model : null, 'detail' => $model !== '', 'products' => [], 'buyers' => [], 'history' => []];
        }

        if ($model !== '') {
            return [
                'model' => $model,
                'detail' => true,
                'buyers' => $this->topBuyers($binaryCompanyId, $model),
                'history' => $this->saleHistory($binaryCompanyId, $model),
            ];
        }

        return [
            'model' => null,
            'detail' => false,
            'products' => $this->productSummary($binaryCompanyId),
        ];
    }

    /**
     * One row per product model, sorted by revenue (highest first).
     *
     * @return list<array{model: string, units: string, invoices: int, minPrice: string, maxPrice: string, avgPrice: string, revenue: string}>
     */
    private function productSummary(string $binaryCompanyId): array
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
               AND (i.archived IS NULL OR i.archived = 0)
             GROUP BY il.description',
            ['companyId' => $binaryCompanyId],
            ['companyId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        $products = [];

        foreach ($rows as $row) {
            $units = (string) ($row['units'] ?? '0');
            $revenueMinor = (string) ($row['revenue'] ?? '0');

            $products[] = [
                'model' => (string) $row['model'],
                'units' => $this->trimQty($units),
                'invoices' => (int) ($row['invoices'] ?? 0),
                'minPrice' => $this->toMajor((string) ($row['minPrice'] ?? '0')),
                'maxPrice' => $this->toMajor((string) ($row['maxPrice'] ?? '0')),
                'avgPrice' => $this->avgUnitPrice($revenueMinor, $units),
                'revenue' => $this->toMajor($revenueMinor),
            ];
        }

        usort($products, static fn (array $a, array $b): int => BigDecimal::of($b['revenue'])->compareTo(BigDecimal::of($a['revenue'])));

        return $products;
    }

    /**
     * Clients who bought a given model, most units first.
     *
     * @return list<array{client: string, units: string, minPrice: string, maxPrice: string, revenue: string}>
     */
    private function topBuyers(string $binaryCompanyId, string $model): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT c.name AS client,
                    SUM(il.qty) AS units,
                    MIN(il.price_amount) AS minPrice,
                    MAX(il.price_amount) AS maxPrice,
                    SUM(il.total_amount) AS revenue
             FROM invoice_lines il
             INNER JOIN invoices i ON i.id = il.invoice_id
             INNER JOIN clients c ON c.id = i.client_id
             WHERE il.company_id = :companyId
               AND il.description = :model
               AND (i.archived IS NULL OR i.archived = 0)
             GROUP BY c.id, c.name',
            ['companyId' => $binaryCompanyId, 'model' => $model],
            ['companyId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        $buyers = [];

        foreach ($rows as $row) {
            $buyers[] = [
                'client' => (string) $row['client'],
                'units' => $this->trimQty((string) ($row['units'] ?? '0')),
                'minPrice' => $this->toMajor((string) ($row['minPrice'] ?? '0')),
                'maxPrice' => $this->toMajor((string) ($row['maxPrice'] ?? '0')),
                'revenue' => $this->toMajor((string) ($row['revenue'] ?? '0')),
            ];
        }

        usort($buyers, static fn (array $a, array $b): int => BigDecimal::of($b['units'])->compareTo(BigDecimal::of($a['units'])));

        return $buyers;
    }

    /**
     * Every individual sale line of a given model, newest first.
     *
     * @return list<array{invoiceId: string, date: ?DateTimeImmutable, client: string, qty: string, price: string, total: string}>
     */
    private function saleHistory(string $binaryCompanyId, string $model): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT i.invoice_id AS invoiceId,
                    i.invoice_date AS saleDate,
                    c.name AS client,
                    il.qty AS qty,
                    il.price_amount AS price,
                    il.total_amount AS total
             FROM invoice_lines il
             INNER JOIN invoices i ON i.id = il.invoice_id
             INNER JOIN clients c ON c.id = i.client_id
             WHERE il.company_id = :companyId
               AND il.description = :model
               AND (i.archived IS NULL OR i.archived = 0)
             ORDER BY i.invoice_date DESC',
            ['companyId' => $binaryCompanyId, 'model' => $model],
            ['companyId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        $history = [];

        foreach ($rows as $row) {
            $history[] = [
                'invoiceId' => (string) ($row['invoiceId'] ?? ''),
                'date' => $this->toDate((string) ($row['saleDate'] ?? '')),
                'client' => (string) ($row['client'] ?? ''),
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
     * Drop a trailing ".0"/".00" from a quantity so "5.00" shows as "5".
     */
    private function trimQty(string $qty): string
    {
        if (! is_numeric($qty)) {
            return '0';
        }

        return (string) BigDecimal::of($qty)->stripTrailingZeros();
    }
}
