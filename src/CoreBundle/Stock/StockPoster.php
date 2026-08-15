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

use DateTimeInterface;
use SolidInvoice\CoreBundle\Entity\CreditNote;
use SolidInvoice\CoreBundle\Entity\Purchase;
use SolidInvoice\CoreBundle\Entity\StockGrade;
use SolidInvoice\CoreBundle\Entity\StockModel;
use SolidInvoice\CoreBundle\Enum\StockMovementReason;
use SolidInvoice\CoreBundle\Repository\StockMovementRepository;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\InvoiceBundle\Entity\Line;
use SolidInvoice\InvoiceBundle\Enum\InvoiceStatus;
use function array_keys;
use function array_unique;
use function is_numeric;
use function round;

/**
 * Turns documents into stock movements.
 *
 * Every document that moves stock - an invoice, a purchase, a credit note -
 * says what it thinks should have moved. This class compares that against what
 * the document has ALREADY put through the ledger and writes only the
 * difference. That one habit is what makes it safe to save the same document
 * over and over: the second save moves nothing, an edited line writes just the
 * change, and a cancellation writes the exact amount back.
 *
 * It matters more than it sounds. The purchase form throws away all its lines
 * and rebuilds them on every save, so anything that simply "posted the lines"
 * would double the stock each time the user pressed Save.
 *
 * Everything here works at ITEM AND GRADE. A Samsung S22 is not one number: it
 * is so many Grade A and so many Grade B, priced and sold separately. Movements
 * name the grade they came out of, and the model total is simply what its
 * grades add up to.
 */
final class StockPoster
{
    public const string SOURCE_INVOICE = 'invoice';

    public const string SOURCE_PURCHASE = 'purchase';

    public const string SOURCE_CREDIT_NOTE = 'credit_note';

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly StockMovementRepository $movementRepository,
    ) {
    }

    /**
     * An invoice takes stock out once it is actually issued to the customer -
     * that is, once it leaves draft. A draft holds nothing, and a cancelled
     * invoice gives it all back.
     *
     * An archived invoice is deliberately left alone. Archiving is filing, not
     * a stock event, and an invoice can be archived either from Draft (nothing
     * ever went out) or from Paid (the goods are long gone) - so re-deciding
     * from the status alone would get one of those two wrong.
     *
     * @return int number of movements written
     */
    public function postInvoice(Invoice $invoice, bool $flush = true, ?string $note = null): int
    {
        $id = (string) $invoice->getId();

        if ($id === '' || $invoice->getStatus() === InvoiceStatus::Archived) {
            return 0;
        }

        return $this->sync(
            sourceType: self::SOURCE_INVOICE,
            sourceId: $id,
            desired: $this->holdsStock($invoice) ? $this->invoiceLines($invoice) : [],
            reason: StockMovementReason::Sale,
            reference: $invoice->getInvoiceId(),
            movedAt: $invoice->getInvoiceDate(),
            note: $note,
            flush: $flush,
        );
    }

    /**
     * A purchase puts stock in from the moment it is entered. There is no draft
     * state on a purchase - if it is in the system, the goods are bought.
     *
     * @return int number of movements written
     */
    public function postPurchase(Purchase $purchase, bool $flush = true, ?string $note = null): int
    {
        $id = (string) $purchase->getId();

        if ($id === '') {
            return 0;
        }

        $desired = [];

        foreach ($purchase->getItems() as $item) {
            $model = $item->getStockModel();

            if (! $model instanceof StockModel) {
                continue;
            }

            $this->add($desired, $model, $item->getStockGrade(), self::units($item->getQty()));
        }

        return $this->sync(
            sourceType: self::SOURCE_PURCHASE,
            sourceId: $id,
            desired: $desired,
            reason: StockMovementReason::Purchase,
            reference: $purchase->getReference(),
            movedAt: $purchase->getPurchaseDate(),
            note: $note,
            flush: $flush,
        );
    }

    /**
     * A credit note puts units back on the shelf only when the returned unit is
     * sellable again. A unit written off as beyond economic repair is a loss,
     * not stock, and a refund with no disposition recorded is left out rather
     * than guessed at - an inflated stock figure is worse than a missing one,
     * because nobody goes looking for it.
     *
     * The unit goes back into the grade it was sold from. Where it comes back
     * in worse condition than it left, that is a regrade afterwards, recorded
     * as its own movement rather than hidden inside the refund.
     *
     * @return int number of movements written
     */
    public function postCreditNote(CreditNote $creditNote, bool $flush = true, ?string $note = null): int
    {
        $id = (string) $creditNote->getId();

        if ($id === '') {
            return 0;
        }

        $desired = [];

        if ($creditNote->getDisposition() === CreditNote::DISPOSITION_REPAIRED) {
            $invoice = $creditNote->getInvoice();
            $returned = $creditNote->getReturnedLines();

            if ($invoice instanceof Invoice && $returned !== []) {
                foreach ($invoice->getLines() as $line) {
                    $model = $line->getStockModel();
                    $qty = $returned[(string) $line->getId()] ?? null;

                    if (! $model instanceof StockModel || $qty === null) {
                        continue;
                    }

                    $this->add($desired, $model, $line->getStockGrade(), self::units($qty));
                }
            }
        }

        return $this->sync(
            sourceType: self::SOURCE_CREDIT_NOTE,
            sourceId: $id,
            desired: $desired,
            reason: StockMovementReason::Return,
            reference: $creditNote->getReference(),
            movedAt: $creditNote->getCreditDate(),
            note: $note,
            flush: $flush,
        );
    }

    /**
     * Give back everything a document had taken, because the document itself is
     * gone. The movements it wrote are kept and cancelled out by opposite ones,
     * so the history still shows what happened and when it was undone.
     *
     * @return int number of movements written
     */
    public function removeSource(string $sourceType, string $sourceId, StockMovementReason $reason, ?string $note = null, bool $flush = true): int
    {
        return $this->sync(
            sourceType: $sourceType,
            sourceId: $sourceId,
            desired: [],
            reason: $reason,
            reference: null,
            movedAt: null,
            note: $note,
            flush: $flush,
        );
    }

    /**
     * Whether an invoice is currently holding stock out of the warehouse.
     */
    public function holdsStock(Invoice $invoice): bool
    {
        return match ($invoice->getStatus()) {
            InvoiceStatus::Pending, InvoiceStatus::Overdue, InvoiceStatus::Paid, InvoiceStatus::Active => true,
            default => false,
        };
    }

    /**
     * What an invoice's lines say should be out, as negative quantities.
     *
     * @return array<string, array{model: StockModel, grade: ?StockGrade, quantity: int}>
     */
    private function invoiceLines(Invoice $invoice): array
    {
        $desired = [];

        foreach ($invoice->getLines() as $line) {
            if (! $line instanceof Line) {
                continue;
            }

            $model = $line->getStockModel();

            if (! $model instanceof StockModel) {
                continue;
            }

            $this->add($desired, $model, $line->getStockGrade(), -self::units($line->getQty()));
        }

        return $desired;
    }

    /**
     * Write the difference between what a document should have moved and what
     * it has moved already.
     *
     * @param array<string, array{model: StockModel, grade: ?StockGrade, quantity: int}> $desired
     */
    private function sync(
        string $sourceType,
        string $sourceId,
        array $desired,
        StockMovementReason $reason,
        ?string $reference,
        ?DateTimeInterface $movedAt,
        ?string $note,
        bool $flush,
    ): int {
        $posted = $this->movementRepository->netBySourceGrouped($sourceType, $sourceId);
        $written = 0;

        foreach (array_unique([...array_keys($desired), ...array_keys($posted)]) as $key) {
            $target = $desired[$key]['quantity'] ?? 0;
            $difference = $target - ($posted[$key] ?? 0);

            if ($difference === 0) {
                continue;
            }

            $model = $desired[$key]['model'] ?? null;
            $grade = $desired[$key]['grade'] ?? null;

            if (! $model instanceof StockModel) {
                // The line that pointed here is gone, so the only thing left to
                // do is give the units back - found through what was posted.
                [$model, $grade] = $this->fromHistory($sourceType, $sourceId, $key);
            }

            if (! $model instanceof StockModel) {
                continue;
            }

            $this->ledger->record(
                model: $model,
                quantity: $difference,
                reason: $reason,
                reference: $reference,
                sourceType: $sourceType,
                sourceId: $sourceId,
                grade: $grade,
                movedAt: $movedAt,
                note: $note,
                flush: false,
            );

            ++$written;
        }

        if ($written > 0 && $flush) {
            $this->ledger->flush();
        }

        return $written;
    }

    /**
     * The item and grade behind a movement this document already wrote, for the
     * case where the line pointing at them has since been removed.
     *
     * @return array{0: ?StockModel, 1: ?StockGrade}
     */
    private function fromHistory(string $sourceType, string $sourceId, string $key): array
    {
        foreach ($this->movementRepository->findForSource($sourceType, $sourceId) as $movement) {
            $model = $movement->getStockModel();

            if (! $model instanceof StockModel) {
                continue;
            }

            $grade = $movement->getStockGrade();

            if (StockMovementRepository::key((string) $model->getId(), $this->gradeId($grade)) === $key) {
                return [$model, $grade];
            }
        }

        return [null, null];
    }

    /**
     * @param array<string, array{model: StockModel, grade: ?StockGrade, quantity: int}> $desired
     */
    private function add(array &$desired, StockModel $model, ?StockGrade $grade, int $quantity): void
    {
        $modelId = (string) $model->getId();

        if ($modelId === '' || $quantity === 0) {
            return;
        }

        // A grade belonging to a different item would silently corrupt both, so
        // it is dropped back to a plain item movement rather than trusted.
        if ($grade instanceof StockGrade && $grade->getStockModel()?->getId()?->equals($model->getId()) !== true) {
            $grade = null;
        }

        $key = StockMovementRepository::key($modelId, $this->gradeId($grade));

        // The same item and grade can appear on more than one line of the same
        // document; that is one net movement, not two competing ones.
        $desired[$key]['model'] = $model;
        $desired[$key]['grade'] = $grade;
        $desired[$key]['quantity'] = ($desired[$key]['quantity'] ?? 0) + $quantity;
    }

    private function gradeId(?StockGrade $grade): ?string
    {
        $id = $grade?->getId();

        return $id === null ? null : (string) $id;
    }

    /**
     * Stock is counted in whole handsets. Line quantities are stored as decimals
     * because the same line type is used for services, so round rather than
     * truncate - 2.0 stored as 1.9999 must still be two phones.
     */
    private static function units(mixed $quantity): int
    {
        return is_numeric($quantity) ? (int) round((float) $quantity) : 0;
    }
}
