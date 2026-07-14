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
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SolidInvoice\CoreBundle\Repository\PurchaseItemRepository;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A single line on a purchase order (one purchased product / service). Mirrors an
 * invoice line so buying is itemised the same way selling is.
 */
#[ORM\Table(name: PurchaseItem::TABLE_NAME)]
#[ORM\Entity(repositoryClass: PurchaseItemRepository::class)]
class PurchaseItem
{
    final public const string TABLE_NAME = 'purchase_item';

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\ManyToOne(targetEntity: Purchase::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'purchase_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Purchase $purchase = null;

    #[ORM\Column(name: 'description', type: Types::STRING, length: 255)]
    private string $description = '';

    #[ORM\Column(name: 'qty', type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $qty = '1';

    #[ORM\Column(name: 'price', type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $price = '0';

    #[ORM\Column(name: 'total', type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $total = '0';

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getPurchase(): ?Purchase
    {
        return $this->purchase;
    }

    public function setPurchase(?Purchase $purchase): self
    {
        $this->purchase = $purchase;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getQty(): string
    {
        return $this->qty;
    }

    public function setQty(string $qty): self
    {
        $this->qty = $qty;

        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function setTotal(string $total): self
    {
        $this->total = $total;

        return $this;
    }

    /**
     * Recalculate this line's total from qty x price and return it.
     */
    public function recalculateTotal(): string
    {
        $lineTotal = BigDecimal::of($this->qty === '' ? '0' : $this->qty)
            ->multipliedBy(BigDecimal::of($this->price === '' ? '0' : $this->price))
            ->toScale(2, RoundingMode::HalfUp);

        $this->total = (string) $lineTotal;

        return $this->total;
    }
}
