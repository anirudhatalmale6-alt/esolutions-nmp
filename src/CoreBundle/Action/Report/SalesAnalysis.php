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
use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\InvoiceBundle\Entity\Line;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function trim;

/**
 * "Selling price by model" report. Reads the line items off every (non-archived)
 * invoice and groups them by product description so the user can see, per model:
 * how many units were sold, the price range and average selling price, and - when
 * a model is selected - the full sale history and the top buyers for it.
 *
 * Amounts on invoice lines are stored in MINOR units (fils/cents), so every money
 * value is divided by 100 for display.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class SalesAnalysis
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[Template('@SolidInvoiceCore/Report/sales_analysis.html.twig')]
    public function __invoke(Request $request): array
    {
        $model = trim((string) $request->query->get('model', ''));

        if ($model !== '') {
            return [
                'model' => $model,
                'detail' => true,
                'buyers' => $this->topBuyers($model),
                'history' => $this->saleHistory($model),
            ];
        }

        return [
            'model' => null,
            'detail' => false,
            'products' => $this->productSummary(),
        ];
    }

    /**
     * One row per product model, sorted by revenue (highest first).
     *
     * @return list<array{model: string, units: string, invoices: int, minPrice: string, maxPrice: string, avgPrice: string, revenue: string}>
     */
    private function productSummary(): array
    {
        $rows = $this->entityManager->createQuery(
            'SELECT l.description AS model,
                    SUM(l.qty) AS units,
                    COUNT(DISTINCT inv.id) AS invoices,
                    MIN(l.price) AS minPrice,
                    MAX(l.price) AS maxPrice,
                    SUM(l.total) AS revenue
             FROM ' . Line::class . ' l
             JOIN l.invoice inv
             GROUP BY l.description'
        )->getScalarResult();

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

        // Sort by revenue descending (kept in PHP so we do not rely on ordering
        // by a DQL aggregate alias).
        usort($products, static fn (array $a, array $b): int => BigDecimal::of($b['revenue'])->compareTo(BigDecimal::of($a['revenue'])));

        return $products;
    }

    /**
     * Clients who bought a given model, most units first.
     *
     * @return list<array{client: string, units: string, minPrice: string, maxPrice: string, revenue: string}>
     */
    private function topBuyers(string $model): array
    {
        $rows = $this->entityManager->createQuery(
            'SELECT c.name AS client,
                    SUM(l.qty) AS units,
                    MIN(l.price) AS minPrice,
                    MAX(l.price) AS maxPrice,
                    SUM(l.total) AS revenue
             FROM ' . Line::class . ' l
             JOIN l.invoice inv
             JOIN inv.client c
             WHERE l.description = :model
             GROUP BY c.id, c.name'
        )->setParameter('model', $model)->getScalarResult();

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
     * @return list<array{invoiceId: string, invoiceUlid: string, date: ?\DateTimeInterface, client: string, qty: string, price: string, total: string}>
     */
    private function saleHistory(string $model): array
    {
        $rows = $this->entityManager->createQuery(
            'SELECT inv.invoiceId AS invoiceId,
                    inv.id AS invoiceUlid,
                    inv.invoiceDate AS date,
                    c.name AS client,
                    l.qty AS qty,
                    l.price AS price,
                    l.total AS total
             FROM ' . Line::class . ' l
             JOIN l.invoice inv
             JOIN inv.client c
             WHERE l.description = :model
             ORDER BY inv.invoiceDate DESC, inv.created DESC'
        )->setParameter('model', $model)->getResult();

        $history = [];

        foreach ($rows as $row) {
            $history[] = [
                'invoiceId' => (string) $row['invoiceId'],
                'invoiceUlid' => (string) $row['invoiceUlid'],
                'date' => $row['date'] ?? null,
                'client' => (string) $row['client'],
                'qty' => $this->trimQty((string) ($row['qty'] ?? '0')),
                'price' => $this->toMajor((string) ($row['price'] ?? '0')),
                'total' => $this->toMajor((string) ($row['total'] ?? '0')),
            ];
        }

        return $history;
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

        $decimal = BigDecimal::of($qty)->stripTrailingZeros();

        return (string) $decimal;
    }
}
