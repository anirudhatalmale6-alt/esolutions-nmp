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

namespace SolidInvoice\CoreBundle\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SolidInvoice\CoreBundle\Enum\StockMovementReason;
use SolidInvoice\CoreBundle\Repository\StockMovementRepository;
use SolidInvoice\CoreBundle\Traits\Entity\CompanyAware;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One line in the stock ledger: a single change to a model's quantity, with the
 * reason for it and a pointer back to the document that caused it.
 *
 * The running quantity on StockModel is the fast answer to "how many do I have";
 * this table is the audit trail behind it. Keeping every change as its own row
 * means a wrong figure can always be traced to the document that caused it, and
 * the quantity can be rebuilt from scratch if it ever drifts.
 *
 * Quantity is SIGNED: positive puts stock in, negative takes it out.
 */
#[ORM\Table(name: StockMovement::TABLE_NAME)]
#[ORM\Index(columns: ['stock_model_id'], name: 'idx_stock_movement_model')]
#[ORM\Index(columns: ['source_type', 'source_id'], name: 'idx_stock_movement_source')]
#[ORM\Entity(repositoryClass: StockMovementRepository::class)]
class StockMovement
{
    final public const string TABLE_NAME = 'stock_movement';

    use TimeStampable;
    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\ManyToOne(targetEntity: StockModel::class)]
    #[ORM\JoinColumn(name: 'stock_model_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?StockModel $stockModel = null;

    /**
     * The grade that moved, where the business tracks them (A / B / Mix and so
     * on). Optional - a movement can be against the model as a whole.
     */
    #[ORM\ManyToOne(targetEntity: StockGrade::class)]
    #[ORM\JoinColumn(name: 'stock_grade_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?StockGrade $stockGrade = null;

    /** Signed: + puts stock in, - takes it out. */
    #[ORM\Column(name: 'quantity', type: Types::INTEGER)]
    private int $quantity = 0;

    #[ORM\Column(name: 'reason', type: Types::STRING, length: 32, enumType: StockMovementReason::class)]
    private StockMovementReason $reason = StockMovementReason::Adjustment;

    /**
     * The document behind the movement - an invoice number, a purchase
     * reference, or free text for a manual correction.
     */
    #[ORM\Column(name: 'reference', type: Types::STRING, length: 191, nullable: true)]
    private ?string $reference = null;

    /**
     * Type and id of the record that caused this, so a movement can be found
     * again (and reversed) when that record is cancelled or deleted. Kept as
     * loose columns rather than real relations because the source can be an
     * invoice, a purchase or a credit note.
     */
    #[ORM\Column(name: 'source_type', type: Types::STRING, length: 32, nullable: true)]
    private ?string $sourceType = null;

    #[ORM\Column(name: 'source_id', type: Types::STRING, length: 64, nullable: true)]
    private ?string $sourceId = null;

    #[ORM\Column(name: 'note', type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** The date the stock actually moved, which is not always today. */
    #[ORM\Column(name: 'moved_at', type: Types::DATE_MUTABLE)]
    private ?DateTimeInterface $movedAt = null;

    /** Who recorded it, for the audit trail. Plain name - users can be deleted. */
    #[ORM\Column(name: 'recorded_by', type: Types::STRING, length: 191, nullable: true)]
    private ?string $recordedBy = null;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getStockModel(): ?StockModel
    {
        return $this->stockModel;
    }

    public function setStockModel(?StockModel $stockModel): self
    {
        $this->stockModel = $stockModel;

        return $this;
    }

    public function getStockGrade(): ?StockGrade
    {
        return $this->stockGrade;
    }

    public function setStockGrade(?StockGrade $stockGrade): self
    {
        $this->stockGrade = $stockGrade;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getReason(): StockMovementReason
    {
        return $this->reason;
    }

    public function setReason(StockMovementReason $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function setSourceType(?string $sourceType): self
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    public function getSourceId(): ?string
    {
        return $this->sourceId;
    }

    public function setSourceId(?string $sourceId): self
    {
        $this->sourceId = $sourceId;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function getMovedAt(): ?DateTimeInterface
    {
        return $this->movedAt;
    }

    public function setMovedAt(?DateTimeInterface $movedAt): self
    {
        $this->movedAt = $movedAt;

        return $this;
    }

    public function getRecordedBy(): ?string
    {
        return $this->recordedBy;
    }

    public function setRecordedBy(?string $recordedBy): self
    {
        $this->recordedBy = $recordedBy;

        return $this;
    }

    /**
     * True when this movement puts stock in. Reads off the sign rather than the
     * reason, so a negative adjustment is correctly reported as outbound.
     */
    public function isInbound(): bool
    {
        return $this->quantity >= 0;
    }
}
