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

namespace SolidInvoice\CoreBundle\Stock;

use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\StockGrade;
use SolidInvoice\CoreBundle\Entity\StockModel;
use SolidInvoice\CoreBundle\Repository\StockModelRepository;
use function is_numeric;
use function round;
use function strcasecmp;
use function trim;

/**
 * Parses a Tally "Stock Summary" Excel export and replaces the current
 * company's stock with its contents.
 *
 * The Tally layout is: a model row (name + total quantity/rate/value) followed
 * by one or more grade rows whose quantities add back up to the model total.
 * There is no indentation to rely on, so grades are grouped by that
 * quantity-sum invariant.
 */
final class StockImporter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StockModelRepository $stockModelRepository,
    ) {
    }

    /**
     * @return array{models: int, grades: int, quantity: int, value: string}
     */
    public function import(string $filePath, Company $company): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);

        $data = $this->extractRows($rows);
        $grouped = $this->groupIntoModels($data);

        // Replace the previous import for this company (grades cascade-delete).
        $this->stockModelRepository->deleteForCompany($company);

        $gradeCount = 0;
        $totalQuantity = 0;
        $totalValue = BigDecimal::zero();

        foreach ($grouped as $entry) {
            $model = new StockModel();
            $model->setCompany($company)
                ->setName($entry['model']['name'])
                ->setQuantity($entry['model']['qty'])
                ->setRate($entry['model']['rate'])
                ->setValue($entry['model']['value']);

            foreach ($entry['grades'] as $gradeRow) {
                $grade = new StockGrade();
                $grade->setGrade($gradeRow['name'])
                    ->setQuantity($gradeRow['qty'])
                    ->setRate($gradeRow['rate'])
                    ->setValue($gradeRow['value']);
                $model->addGrade($grade);
                $this->entityManager->persist($grade);
                ++$gradeCount;
            }

            $this->entityManager->persist($model);
            $totalQuantity += $entry['model']['qty'];
            $totalValue = $totalValue->plus(BigDecimal::of($entry['model']['value']));
        }

        $this->entityManager->flush();

        return [
            'models' => count($grouped),
            'grades' => $gradeCount,
            'quantity' => $totalQuantity,
            'value' => (string) $totalValue->toScale(2),
        ];
    }

    /**
     * Keep only real data rows: a non-empty name and a numeric quantity,
     * dropping the Tally title/header rows and the Grand Total footer.
     *
     * @param array<int, array<int, mixed>> $rows
     * @return list<array{name: string, qty: int, rate: string, value: string}>
     */
    private function extractRows(array $rows): array
    {
        $data = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row[0] ?? ''));
            $qty = $row[1] ?? null;

            if ($name === '' || strcasecmp($name, 'grand total') === 0 || ! is_numeric($qty)) {
                continue;
            }

            $data[] = [
                'name' => $name,
                'qty' => (int) round((float) $qty),
                'rate' => is_numeric($row[2] ?? null) ? (string) $row[2] : '0',
                'value' => is_numeric($row[3] ?? null) ? (string) $row[3] : '0',
            ];
        }

        return $data;
    }

    /**
     * Group flat rows into models with their grade children using the
     * quantity-sum invariant (a model's grades add up to its quantity).
     *
     * @param list<array{name: string, qty: int, rate: string, value: string}> $data
     * @return list<array{model: array{name: string, qty: int, rate: string, value: string}, grades: list<array{name: string, qty: int, rate: string, value: string}>}>
     */
    private function groupIntoModels(array $data): array
    {
        $models = [];
        $count = count($data);
        $i = 0;

        while ($i < $count) {
            $model = $data[$i];
            $grades = [];
            $accumulated = 0;
            $j = $i + 1;

            while ($j < $count) {
                $grades[] = $data[$j];
                $accumulated += $data[$j]['qty'];
                ++$j;

                if ($accumulated >= $model['qty']) {
                    break;
                }
            }

            $models[] = ['model' => $model, 'grades' => $grades];
            $i = $j;
        }

        return $models;
    }
}
