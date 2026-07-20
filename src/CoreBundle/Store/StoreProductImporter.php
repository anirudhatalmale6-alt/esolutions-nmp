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

namespace SolidInvoice\CoreBundle\Store;

use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\StoreProduct;
use SolidInvoice\CoreBundle\Repository\StoreProductRepository;
use function array_key_exists;
use function count;
use function in_array;
use function preg_replace;
use function str_replace;
use function strtolower;
use function trim;

/**
 * Parses the "MobilesOnline Product Upload" Excel sheet the shop owner fills in
 * and syncs it into the storefront catalogue.
 *
 * Columns are located BY HEADER NAME (not position), so the owner can reorder or
 * omit optional columns and it still works. Rows are matched to existing
 * products by SKU and UPDATED IN PLACE - a re-upload changes prices/stock/text
 * without duplicating rows and, crucially, WITHOUT wiping a photo they uploaded
 * by hand after the first import.
 */
final class StoreProductImporter
{
    /**
     * Header text (normalised) -> product field. Several spellings map to the
     * same field so the owner is not forced to match wording exactly.
     *
     * @var array<string, string>
     */
    private const HEADER_MAP = [
        'sku' => 'sku',
        'sku / code' => 'sku',
        'sku/code' => 'sku',
        'code' => 'sku',
        'make' => 'make',
        'brand' => 'make',
        'model' => 'model',
        'storage' => 'storage',
        'color' => 'color',
        'colour' => 'color',
        'condition' => 'condition',
        'grade' => 'condition',
        'key specs' => 'keySpecs',
        'specs' => 'keySpecs',
        'specifications' => 'keySpecs',
        'description' => 'description',
        'regular price (aed)' => 'regularPrice',
        'regular price' => 'regularPrice',
        'real price' => 'regularPrice',
        'price' => 'regularPrice',
        'sale price (aed)' => 'salePrice',
        'sale price' => 'salePrice',
        'featured' => 'featured',
        'in stock' => 'inStock',
        'instock' => 'inStock',
        'stock' => 'inStock',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StoreProductRepository $storeProductRepository,
    ) {
    }

    /**
     * @return array{created: int, updated: int, total: int}
     */
    public function import(string $filePath, Company $company): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        $sheet = $spreadsheet->getSheetByName('Products') ?? $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);

        [$columnMap, $headerIndex] = $this->locateColumns($rows);

        if (! array_key_exists('make', $columnMap) || ! array_key_exists('model', $columnMap)) {
            throw new RuntimeException('Could not find the "Make" and "Model" columns. Please use the provided template.');
        }

        // Highest existing position so newly added products append after the rest.
        $position = count($this->storeProductRepository->findForCompany($company));

        $created = 0;
        $updated = 0;

        $rowCount = count($rows);
        for ($r = $headerIndex + 1; $r < $rowCount; ++$r) {
            $row = $rows[$r];

            $make = $this->cell($row, $columnMap, 'make');
            $model = $this->cell($row, $columnMap, 'model');

            // Skip blank rows and the template's example/notes lines.
            if ($make === '' && $model === '') {
                continue;
            }

            $sku = $this->cell($row, $columnMap, 'sku');
            if ($sku === '') {
                $sku = $this->slug($make . '-' . $model . '-' . $this->cell($row, $columnMap, 'storage'));
            }

            $product = $this->storeProductRepository->findOneBySkuForCompany($company, $sku);

            if ($product instanceof StoreProduct) {
                ++$updated;
            } else {
                $product = new StoreProduct();
                $product->setCompany($company)
                    ->setSku($sku)
                    ->setPosition($position++);
                $this->entityManager->persist($product);
                ++$created;
            }

            $regular = $this->parsePrice($this->cell($row, $columnMap, 'regularPrice'));
            $sale = $this->parsePrice($this->cell($row, $columnMap, 'salePrice'));

            $product
                ->setMake($make)
                ->setModel($model)
                ->setStorage($this->nullable($this->cell($row, $columnMap, 'storage')))
                ->setColor($this->nullable($this->cell($row, $columnMap, 'color')))
                ->setCondition($this->nullable($this->cell($row, $columnMap, 'condition')))
                ->setKeySpecs($this->nullable($this->cell($row, $columnMap, 'keySpecs')))
                ->setDescription($this->nullable($this->cell($row, $columnMap, 'description')))
                ->setRegularPrice($regular ?? '0')
                ->setSalePrice($sale)
                ->setFeatured($this->parseBool($this->cell($row, $columnMap, 'featured'), false))
                ->setInStock($this->parseBool($this->cell($row, $columnMap, 'inStock'), true));
        }

        $this->entityManager->flush();

        return [
            'created' => $created,
            'updated' => $updated,
            'total' => $created + $updated,
        ];
    }

    /**
     * Find the header row (the one carrying Make + Model) and map each recognised
     * header to its column index.
     *
     * @param array<int, array<int, mixed>> $rows
     * @return array{0: array<string, int>, 1: int} [field => columnIndex, headerRowIndex]
     */
    private function locateColumns(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $map = [];
            foreach ($row as $col => $value) {
                $key = $this->normaliseHeader((string) ($value ?? ''));
                if ($key !== '' && array_key_exists($key, self::HEADER_MAP)) {
                    $field = self::HEADER_MAP[$key];
                    // First column wins if a header appears twice.
                    if (! array_key_exists($field, $map)) {
                        $map[$field] = $col;
                    }
                }
            }

            if (array_key_exists('make', $map) && array_key_exists('model', $map)) {
                return [$map, $index];
            }
        }

        return [[], -1];
    }

    private function normaliseHeader(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace('*', '', $value);
        $value = (string) preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    /**
     * @param array<int, mixed> $row
     * @param array<string, int> $columnMap
     */
    private function cell(array $row, array $columnMap, string $field): string
    {
        if (! array_key_exists($field, $columnMap)) {
            return '';
        }

        $col = $columnMap[$field];

        return trim((string) ($row[$col] ?? ''));
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function parsePrice(string $value): ?string
    {
        // Keep digits, dot and minus only (strips "AED", commas, spaces).
        $clean = (string) preg_replace('/[^0-9.\-]/', '', $value);

        if ($clean === '' || $clean === '-' || $clean === '.') {
            return null;
        }

        return $clean;
    }

    private function parseBool(string $value, bool $default): bool
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return $default;
        }

        return in_array($value, ['yes', 'y', 'true', '1', 'in stock', 'available'], true);
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim($value, '-');
    }
}
