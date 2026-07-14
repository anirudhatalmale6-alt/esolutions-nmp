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

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SolidInvoice\ClientBundle\Entity\Client;
use SolidInvoice\CoreBundle\Repository\PurchaseRepository;
use SolidInvoice\CoreBundle\Traits\Entity\CompanyAware;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A purchase (bill) from a supplier. This is a buy/payable record only - it does
 * not affect stock, which comes from the Tally import.
 */
#[ORM\Table(name: Purchase::TABLE_NAME)]
#[ORM\Entity(repositoryClass: PurchaseRepository::class)]
class Purchase
{
    final public const string TABLE_NAME = 'purchase';

    use TimeStampable;
    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Client $client = null;

    #[ORM\Column(name: 'reference', type: Types::STRING, length: 128, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(name: 'purchase_date', type: Types::DATE_MUTABLE)]
    private ?DateTimeInterface $purchaseDate = null;

    #[ORM\Column(name: 'description', type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'total_amount', type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $totalAmount = '0';

    #[ORM\Column(name: 'amount_paid', type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amountPaid = '0';

    /**
     * @var Collection<int, PurchaseItem>
     */
    #[ORM\OneToMany(mappedBy: 'purchase', targetEntity: PurchaseItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): self
    {
        $this->client = $client;

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

    public function getPurchaseDate(): ?DateTimeInterface
    {
        return $this->purchaseDate;
    }

    public function setPurchaseDate(?DateTimeInterface $purchaseDate): self
    {
        $this->purchaseDate = $purchaseDate;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(string $totalAmount): self
    {
        $this->totalAmount = $totalAmount;

        return $this;
    }

    public function getAmountPaid(): string
    {
        return $this->amountPaid;
    }

    public function setAmountPaid(string $amountPaid): self
    {
        $this->amountPaid = $amountPaid;

        return $this;
    }

    /**
     * @return Collection<int, PurchaseItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(PurchaseItem $item): self
    {
        if (! $this->items->contains($item)) {
            $this->items->add($item);
            $item->setPurchase($this);
        }

        return $this;
    }

    public function removeItem(PurchaseItem $item): self
    {
        if ($this->items->removeElement($item) && $item->getPurchase() === $this) {
            $item->setPurchase(null);
        }

        return $this;
    }

    public function clearItems(): self
    {
        foreach ($this->items->toArray() as $item) {
            $this->removeItem($item);
        }

        return $this;
    }

    /**
     * Recalculate each line and set the purchase total to the sum of the lines.
     */
    public function recalculateTotalFromItems(): self
    {
        $total = BigDecimal::zero();

        foreach ($this->items as $item) {
            $total = $total->plus(BigDecimal::of($item->recalculateTotal()));
        }

        $this->totalAmount = (string) $total->toScale(2, RoundingMode::HalfUp);

        return $this;
    }

    /**
     * Outstanding balance still owed to the supplier (total - paid), never below 0.
     */
    public function getBalance(): string
    {
        $balance = BigDecimal::of($this->totalAmount)->minus(BigDecimal::of($this->amountPaid));

        if ($balance->isNegative()) {
            $balance = BigDecimal::zero();
        }

        return (string) $balance->toScale(2);
    }

    /**
     * @return 'paid'|'partial'|'unpaid'
     */
    public function getStatus(): string
    {
        $paid = BigDecimal::of($this->amountPaid);
        $total = BigDecimal::of($this->totalAmount);

        if ($paid->isGreaterThanOrEqualTo($total) && $total->isPositive()) {
            return 'paid';
        }

        if ($paid->isPositive()) {
            return 'partial';
        }

        return 'unpaid';
    }
}
