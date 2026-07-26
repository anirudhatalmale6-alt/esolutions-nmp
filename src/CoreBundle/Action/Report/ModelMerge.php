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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use function array_filter;
use function array_values;
use function count;
use function is_string;
use function trim;

/**
 * "Merge / rename models" screen for the Sales-by-Model report.
 *
 * Lists every distinct model name as it was typed across the invoices, together
 * with what it currently counts as. The owner ticks the variants that are really
 * the same phone and merges them onto one correct name; that mapping is stored in
 * model_alias and the report immediately groups them as one - across past
 * invoices too. Submitting with a blank target instead un-merges the ticked names
 * back to standalone.
 *
 * The invoices themselves are never modified - only the report's grouping.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ModelMerge extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CompanySelector $companySelector,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $companyId = $this->companySelector->getCompany();
        $binaryCompanyId = $companyId?->toBinary();

        if ($binaryCompanyId === null) {
            return $this->render('@SolidInvoiceCore/Report/model_merge.html.twig', ['models' => [], 'names' => []]);
        }

        if ($request->isMethod('POST')) {
            return $this->handleMerge($request, $binaryCompanyId);
        }

        $models = $this->modelsInUse($binaryCompanyId);

        // The list of names a variant can be merged INTO = every name currently
        // shown, so the owner picks an existing one (or types a fresh name).
        $names = array_values(array_unique(array_map(static fn (array $m): string => $m['current'], $models)));
        sort($names);

        return $this->render('@SolidInvoiceCore/Report/model_merge.html.twig', [
            'models' => $models,
            'names' => $names,
        ]);
    }

    private function handleMerge(Request $request, string $binaryCompanyId): Response
    {
        if (! $this->isCsrfTokenValid('model.merge', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_sales_model_merge');
        }

        $target = trim((string) $request->request->get('target', ''));

        if (mb_strlen($target) > 255) {
            $this->addFlash('error', 'That name is too long, please shorten it.');

            return $this->redirectToRoute('_sales_model_merge');
        }

        // Aliases are the model names as typed; the column holds up to 255 chars,
        // so drop anything blank or unexpectedly long rather than let it error.
        /** @var list<string> $aliases */
        $aliases = array_values(array_filter(
            (array) $request->request->all('aliases'),
            static fn ($value): bool => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 255
        ));

        if ($aliases === []) {
            $this->addFlash('error', 'Tick at least one model name first.');

            return $this->redirectToRoute('_sales_model_merge');
        }

        if ($target === '') {
            // Blank target = un-merge: drop the stored mapping for these names so
            // they stand on their own again.
            $removed = 0;
            foreach ($aliases as $alias) {
                $removed += (int) $this->connection->executeStatement(
                    'DELETE FROM model_alias WHERE company_id = :companyId AND alias = :alias',
                    ['companyId' => $binaryCompanyId, 'alias' => $alias],
                    ['companyId' => ParameterType::BINARY]
                );
            }

            $this->addFlash('success', sprintf('Reset %d model name(s) back to standalone.', $removed));

            return $this->redirectToRoute('_sales_model_merge');
        }

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $merged = 0;

        foreach ($aliases as $alias) {
            $this->connection->executeStatement(
                'INSERT INTO model_alias (id, company_id, alias, canonical, created, updated)
                 VALUES (:id, :companyId, :alias, :canonical, :created, :updated)
                 ON DUPLICATE KEY UPDATE canonical = VALUES(canonical), updated = VALUES(updated)',
                [
                    'id' => (new Ulid())->toBinary(),
                    'companyId' => $binaryCompanyId,
                    'alias' => $alias,
                    'canonical' => $target,
                    'created' => $now,
                    'updated' => $now,
                ],
                [
                    'id' => ParameterType::BINARY,
                    'companyId' => ParameterType::BINARY,
                ]
            );
            ++$merged;
        }

        $this->addFlash('success', sprintf('Merged %d model name(s) into "%s". Your report now counts them as one.', $merged, $target));

        return $this->redirectToRoute('_sales_model_merge');
    }

    /**
     * Every distinct typed model name, with what it currently counts as, its unit
     * count and revenue. Ordered so mapped variants sit next to their target.
     *
     * @return list<array{raw: string, current: string, mapped: bool, units: string, revenue: string, invoices: int}>
     */
    private function modelsInUse(string $binaryCompanyId): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT il.description AS raw,
                    COALESCE(ma.canonical, il.description) AS current,
                    ma.canonical AS mapped,
                    SUM(il.qty) AS units,
                    SUM(il.total_amount) AS revenue,
                    COUNT(DISTINCT il.invoice_id) AS invoices
             FROM invoice_lines il
             INNER JOIN invoices i ON i.id = il.invoice_id
             LEFT JOIN model_alias ma ON ma.company_id = il.company_id AND ma.alias = il.description
             WHERE il.company_id = :companyId
               AND (i.archived IS NULL OR i.archived = 0)
             GROUP BY il.description, ma.canonical
             ORDER BY COALESCE(ma.canonical, il.description) ASC, il.description ASC',
            ['companyId' => $binaryCompanyId],
            ['companyId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        $models = [];

        foreach ($rows as $row) {
            $raw = (string) ($row['raw'] ?? '');

            if (trim($raw) === '') {
                continue;
            }

            $revenueMinor = (string) ($row['revenue'] ?? '0');

            $models[] = [
                'raw' => $raw,
                'current' => (string) ($row['current'] ?? $raw),
                'mapped' => ($row['mapped'] ?? null) !== null,
                'units' => $this->trimQty((string) ($row['units'] ?? '0')),
                'revenue' => $this->toMajor($revenueMinor),
                'invoices' => (int) ($row['invoices'] ?? 0),
            ];
        }

        return $models;
    }

    private function toMajor(string $minor): string
    {
        if ($minor === '' || ! is_numeric($minor)) {
            return '0.00';
        }

        return (string) BigDecimal::of($minor)->dividedBy(100, 2, RoundingMode::HalfUp);
    }

    private function trimQty(string $qty): string
    {
        if (! is_numeric($qty)) {
            return '0';
        }

        $qty = (string) $qty;

        if (str_contains($qty, '.')) {
            $qty = rtrim(rtrim($qty, '0'), '.');
        }

        return $qty === '' || $qty === '-0' ? '0' : $qty;
    }
}
