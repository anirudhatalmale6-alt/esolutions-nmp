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
use LogicException;
use SolidInvoice\CoreBundle\Entity\StockGrade;
use SolidInvoice\CoreBundle\Entity\StockModel;
use SolidInvoice\CoreBundle\Entity\StockMovement;
use SolidInvoice\CoreBundle\Enum\StockMovementReason;
use SolidInvoice\CoreBundle\Repository\StockMovementRepository;
use Symfony\Bundle\SecurityBundle\Security;
use function method_exists;

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

        $model->setQuantity($model->getQuantity() + $quantity);

        if ($grade instanceof StockGrade) {
            $grade->setQuantity($grade->getQuantity() + $quantity);
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
     * Set a model's quantity to a counted figure, writing the difference as an
     * adjustment. This is the stock-take path: the user says what is on the
     * shelf, and the ledger records the correction that got it there.
     */
    public function setCountedQuantity(StockModel $model, int $counted, ?string $note = null): ?StockMovement
    {
        $difference = $counted - $model->getQuantity();

        if ($difference === 0) {
            return null;
        }

        return $this->record(
            model: $model,
            quantity: $difference,
            reason: StockMovementReason::Adjustment,
            reference: 'Stock take',
            note: $note,
        );
    }

    /**
     * Whether a model's running quantity still agrees with its history. Any
     * disagreement means something changed stock without going through here.
     */
    public function isInSync(StockModel $model): bool
    {
        return $this->movementRepository->netQuantityForModel($model) === $model->getQuantity();
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
