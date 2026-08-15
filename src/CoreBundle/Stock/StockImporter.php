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
use SolidInvoice\CoreBundle\Entity\StockMovement;
use SolidInvoice\CoreBundle\Enum\StockMovementReason;
use SolidInvoice\CoreBundle\Repository\StockModelRepository;
use SolidInvoice\CoreBundle\Repository\StockMovementRepository;
use function count;
use function is_numeric;
use function round;
use function strcasecmp;
use function strtolower;
use function trim;

/**
 * Parses a Tally "Stock Summary" Excel export and sets the current company's
 * stock from its contents.
 *
 * The Tally layout is: a model row (name + total quantity/rate/value) followed
 * by one or more grade rows whose quantities add back up to the model total.
 * There is no indentation to rely on, so grades are grouped by that
 * quantity-sum invariant.
 *
 * This is the OPENING FIGURE path, and it is the only place allowed to declare
 * a quantity outright. It used to delete the company's stock and build it
 * again from the sheet, which had two costs that only showed up later: every
 * invoice and purchase line lost the item it pointed at (the foreign key is
 * ON DELETE SET NULL), and the whole movement history went with the models
 * (ON DELETE CASCADE). So it now matches on the item's name and adjusts what
 * is already there, writing the change as a Baseline movement so even the
 * opening figure has a row explaining it.
 */
final class StockImporter
{
    /** Tags the movements this import writes, so the opening figure is traceable. */
    public const string SOURCE_IMPORT = 'tally_import';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StockModelRepository $stockModelRepository,
        private readonly StockMovementRepository $movementRepository,
        private readonly StockLedger $ledger,
    ) {
    }

    /**
     * @param bool $force set the opening figure again even though the system has
     *                    been counting stock itself - discards what it counted
     *
     * @throws StockAlreadyLiveException
     *
     * @return array{models: int, grades: int, quantity: int, value: string, created: int, updated: int, cleared: int, adjusted: int}
     */
    public function import(string $filePath, Company $company, bool $force = false): array
    {
        $liveMovements = $this->movementRepository->countLiveMovementsForCompany($company);

        if ($liveMovements > 0 && ! $force) {
            throw new StockAlreadyLiveException($liveMovements);
        }

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);

        $grouped = $this->groupIntoModels($this->extractRows($rows));

        $existing = [];

        foreach ($this->stockModelRepository->findForCompany($company) as $model) {
            $existing[$this->key($model->getName())] = $model;
        }

        $seen = [];
        $gradeCount = 0;
        $totalQuantity = 0;
        $created = 0;
        $adjusted = 0;
        $totalValue = BigDecimal::zero();

        foreach ($grouped as $entry) {
            $key = $this->key($entry['model']['name']);
            $model = $existing[$key] ?? null;

            if (! $model instanceof StockModel) {
                $model = new StockModel();
                $model->setCompany($company)
                    ->setName($entry['model']['name']);
                $this->entityManager->persist($model);
                $existing[$key] = $model;
                ++$created;
            }

            $model->setRate($entry['model']['rate'])
                ->setValue($entry['model']['value']);

            $this->replaceGrades($model, $entry['grades']);
            $gradeCount += count($entry['grades']);

            if ($this->setOpeningQuantity($model, $entry['model']['qty']) !== null) {
                ++$adjusted;
            }

            $seen[$key] = true;
            $totalQuantity += $entry['model']['qty'];
            $totalValue = $totalValue->plus(BigDecimal::of($entry['model']['value']));
        }

        // An item the app knows about that the sheet no longer lists is out of
        // stock, not untouched - the Tally summary lists everything held. It is
        // taken to zero rather than deleted, so the invoices and purchases that
        // point at it keep pointing at something.
        $cleared = 0;

        foreach ($existing as $key => $model) {
            if (! isset($seen[$key]) && $this->setOpeningQuantity($model, 0) !== null) {
                ++$cleared;
            }
        }

        $this->entityManager->flush();

        return [
            'models' => count($grouped),
            'grades' => $gradeCount,
            'quantity' => $totalQuantity,
            'value' => (string) $totalValue->toScale(2),
            'created' => $created,
            'updated' => count($grouped) - $created,
            'cleared' => $cleared,
            'adjusted' => $adjusted,
        ];
    }

    /**
     * Move an item to the figure on the sheet, recording the difference as the
     * opening stock. Nothing is written when it already agrees, so re-uploading
     * the same sheet does not litter the history with no-op rows.
     */
    private function setOpeningQuantity(StockModel $model, int $quantity): ?StockMovement
    {
        $difference = $quantity - $model->getQuantity();

        if ($difference === 0) {
            return null;
        }

        return $this->ledger->record(
            model: $model,
            quantity: $difference,
            reason: StockMovementReason::Baseline,
            reference: 'Tally stock import',
            sourceType: self::SOURCE_IMPORT,
            note: 'Opening figure taken from the Tally stock summary',
            flush: false,
        );
    }

    /**
     * Grades are re-read from the sheet each time. They are a breakdown of the
     * model total for display, not something invoices move on their own, so
     * replacing them wholesale is safe where replacing the model is not.
     *
     * @param list<array{name: string, qty: int, rate: string, value: string}> $gradeRows
     */
    private function replaceGrades(StockModel $model, array $gradeRows): void
    {
        foreach ($model->getGrades() as $grade) {
            $model->removeGrade($grade);
        }

        foreach ($gradeRows as $gradeRow) {
            $grade = new StockGrade();
            $grade->setGrade($gradeRow['name'])
                ->setQuantity($gradeRow['qty'])
                ->setRate($gradeRow['rate'])
                ->setValue($gradeRow['value']);
            $model->addGrade($grade);
            $this->entityManager->persist($grade);
        }
    }

    /**
     * Match on the item name the way a person would: case and surrounding
     * spaces do not make it a different phone.
     */
    private function key(string $name): string
    {
        return strtolower(trim($name));
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
