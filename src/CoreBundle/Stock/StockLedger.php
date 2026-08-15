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

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use SolidInvoice\CoreBundle\Entity\StockGrade;
use SolidInvoice\CoreBundle\Entity\StockModel;
use SolidInvoice\CoreBundle\Entity\StockMovement;
use SolidInvoice\CoreBundle\Enum\StockMovementReason;
use SolidInvoice\CoreBundle\Repository\StockMovementRepository;
use Symfony\Bundle\SecurityBundle\Security;
use function method_exists;
use function sprintf;

/**
 * The single way stock quantities are allowed to change.
 *
 * Everything that moves stock - a purchase received, an invoice raised, a
 * return, a hand correction - goes through here, so that the ledger row and the
 * running quantity on StockModel are always written together. Nothing else
 * should call setQuantity() on a StockModel; if it does, the running figure and
 * the history drift apart and neither can be trusted.
 *
 * Quantities are SIGNED throughout: positive in, negative out.
 */
final class StockLedger
{
    /**
     * Takes the registry rather than the entity manager itself.
     *
     * The ledger is reached from a Doctrine event listener, and a listener that
     * asks for the entity manager by name sits inside the graph that builds it.
     * The registry hands one over on demand instead, which keeps this out of
     * that knot entirely.
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly StockMovementRepository $movementRepository,
        private readonly Security $security,
    ) {
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(StockMovement::class);

        if (! $manager instanceof EntityManagerInterface) {
            throw new LogicException('No entity manager is configured for stock movements.');
        }

        return $manager;
    }

    /**
     * Record a movement and apply it to the model's running quantity.
     *
     * $sourceType / $sourceId tie the movement back to the document that caused
     * it, so {@see StockPoster} can work out later what that document still
     * owes - and undo it exactly when the document is cancelled or deleted.
     */
    public function record(
        StockModel $model,
        int $quantity,
        StockMovementReason $reason,
        ?string $reference = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?StockGrade $grade = null,
        ?DateTimeInterface $movedAt = null,
        ?string $note = null,
        bool $flush = true,
        bool $apply = true,
    ): StockMovement {
        $movement = new StockMovement();
        $movement->setCompany($model->getCompany())
            ->setStockModel($model)
            ->setStockGrade($grade)
            ->setQuantity($quantity)
            ->setReason($reason)
            ->setReference($reference)
            ->setSourceType($sourceType)
            ->setSourceId($sourceId)
            ->setNote($note)
            ->setMovedAt($movedAt ?? new DateTimeImmutable('today'))
            ->setRecordedBy($this->currentUserName());

        $this->entityManager()->persist($movement);

        // $apply: false writes the movement WITHOUT moving anything, for the one
        // case where the quantity is already correct and what is missing is the
        // row explaining it - the opening figure of a business that is switching
        // its stock live. Everything else moves the figure.
        if ($apply) {
            $model->setQuantity($model->getQuantity() + $quantity);

            if ($grade instanceof StockGrade) {
                $grade->setQuantity($grade->getQuantity() + $quantity);
            }
        }

        if ($flush) {
            $this->entityManager()->flush();
        }

        return $movement;
    }

    /**
     * Write out movements recorded with flush: false.
     *
     * Undoing a document is not done by deleting its movements - it is done by
     * writing opposite ones (see {@see StockPoster}), so the history keeps
     * showing that the stock went out and then came back, which is what
     * actually happened.
     */
    public function flush(): void
    {
        $this->entityManager()->flush();
    }

    /**
     * Set a counted figure, writing the difference as an adjustment. This is
     * the stock-take path: the user says what is on the shelf, and the ledger
     * records the correction that got it there.
     *
     * Counting happens per grade where the item has grades, because that is
     * what is physically stacked on the shelf - "twelve S22" is not something
     * anybody can verify, "twelve Grade A S22" is.
     */
    public function setCountedQuantity(StockModel $model, int $counted, ?string $note = null, ?StockGrade $grade = null): ?StockMovement
    {
        $current = $grade instanceof StockGrade ? $grade->getQuantity() : $model->getQuantity();
        $difference = $counted - $current;

        if ($difference === 0) {
            return null;
        }

        return $this->record(
            model: $model,
            quantity: $difference,
            reason: StockMovementReason::Adjustment,
            reference: 'Stock take',
            grade: $grade,
            note: $note,
        );
    }

    /**
     * Move units between two grades of the same item.
     *
     * Stock booked in as Grade A that turns out to be Grade C has not arrived
     * and has not been sold - it has moved sideways. Recording it as two
     * opposite movements keeps the item's total untouched while showing both
     * halves of what happened, which a pair of unrelated corrections would not.
     *
     * @return array{0: StockMovement, 1: StockMovement}
     *
     * @throws InvalidArgumentException when the grades are not both this item's, or the quantity is not positive
     */
    public function regrade(StockModel $model, StockGrade $from, StockGrade $to, int $quantity, ?string $note = null): array
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Enter how many units to move, as a positive number.');
        }

        if ($from->getId()?->equals($to->getId()) === true) {
            throw new InvalidArgumentException('Choose two different grades.');
        }

        foreach ([$from, $to] as $grade) {
            if ($grade->getStockModel()?->getId()?->equals($model->getId()) !== true) {
                throw new InvalidArgumentException('Both grades must belong to the same stock item.');
            }
        }

        if ($quantity > $from->getQuantity()) {
            throw new InvalidArgumentException(sprintf(
                'There are only %d in %s, so %d cannot be moved out of it.',
                $from->getQuantity(),
                $from->getGrade(),
                $quantity,
            ));
        }

        $reference = sprintf('%s to %s', $from->getGrade(), $to->getGrade());

        $out = $this->record(
            model: $model,
            quantity: -$quantity,
            reason: StockMovementReason::Regrade,
            reference: $reference,
            grade: $from,
            note: $note,
            flush: false,
        );

        $in = $this->record(
            model: $model,
            quantity: $quantity,
            reason: StockMovementReason::Regrade,
            reference: $reference,
            grade: $to,
            note: $note,
            flush: false,
        );

        $this->flush();

        return [$out, $in];
    }

    /**
     * Whether a model's running quantity still agrees with its history. Any
     * disagreement means something changed stock without going through here.
     */
    public function isInSync(StockModel $model): bool
    {
        return $this->movementRepository->netQuantityForModel($model) === $model->getQuantity();
    }

    /**
     * Whether an item's grades still add up to the item's own total.
     *
     * They must, because the total is not a separate fact - it is what the
     * grades come to. An item with no grades at all is trivially in step.
     */
    public function gradesAddUp(StockModel $model): bool
    {
        if ($model->getGrades()->isEmpty()) {
            return true;
        }

        $sum = 0;

        foreach ($model->getGrades() as $grade) {
            $sum += $grade->getQuantity();
        }

        return $sum === $model->getQuantity();
    }

    private function currentUserName(): ?string
    {
        $user = $this->security->getUser();

        if ($user === null) {
            return null;
        }

        return method_exists($user, 'getUsername') ? (string) $user->getUsername() : $user->getUserIdentifier();
    }
}
