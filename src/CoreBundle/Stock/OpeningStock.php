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

use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\StockGrade;
use SolidInvoice\CoreBundle\Entity\StockModel;
use SolidInvoice\CoreBundle\Enum\StockMovementReason;
use SolidInvoice\CoreBundle\Repository\StockModelRepository;
use SolidInvoice\CoreBundle\Repository\StockMovementRepository;

/**
 * Writes down what a business already holds, the moment it starts counting.
 *
 * Quantities that came from a Tally upload are just numbers - there is nothing
 * behind them. Once documents start moving stock, every figure has to be
 * explainable, so the figure a business is standing on becomes its opening
 * movement and the history is complete from that point forward.
 *
 * Nothing is added or taken away by this: after it runs, every item holds
 * exactly what it held before.
 */
final class OpeningStock
{
    public function __construct(
        private readonly StockModelRepository $stockModelRepository,
        private readonly StockMovementRepository $movementRepository,
        private readonly StockLedger $ledger,
    ) {
    }

    /**
     * Record the opening figure for every item this business holds that has no
     * history yet.
     *
     * Safe to run more than once: an item that already has any movement is left
     * alone, so a business that is switched off and on again does not get a
     * second opening figure on top of the first.
     *
     * @return int how many opening movements were written
     */
    public function recordFor(Company $company, bool $flush = true): int
    {
        $written = 0;

        foreach ($this->stockModelRepository->findForCompany($company) as $model) {
            if ($this->movementRepository->findForModel($model) !== []) {
                continue;
            }

            $written += $this->record($model);
        }

        if ($written > 0 && $flush) {
            $this->ledger->flush();
        }

        return $written;
    }

    /**
     * Where an item is graded, the opening figure is recorded per grade - that
     * is the level things are sold, counted and regraded at, so it is the level
     * the history has to start at. The item's own total follows from its grades.
     */
    private function record(StockModel $model): int
    {
        $written = 0;

        foreach ($model->getGrades() as $grade) {
            if ($grade->getQuantity() === 0) {
                continue;
            }

            $this->write($model, $grade->getQuantity(), $grade);
            ++$written;
        }

        if ($written === 0 && $model->getQuantity() !== 0) {
            $this->write($model, $model->getQuantity(), null);
            ++$written;
        }

        return $written;
    }

    private function write(StockModel $model, int $quantity, ?StockGrade $grade): void
    {
        $this->ledger->record(
            model: $model,
            quantity: $quantity,
            reason: StockMovementReason::Baseline,
            reference: 'Opening stock',
            sourceType: StockImporter::SOURCE_IMPORT,
            grade: $grade,
            note: 'The quantity held when live stock tracking was switched on',
            flush: false,
            // The units are already on the item. What is missing is the row
            // saying where they came from, not another helping of them.
            apply: false,
        );
    }
}
