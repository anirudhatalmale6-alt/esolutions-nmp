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
use SolidInvoice\CoreBundle\Repository\PurchasePaymentRepository;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A single dated payment made towards a purchase order. A purchase is paid over
 * one or more of these (e.g. part today, the rest next week), so each payment
 * lands on the correct day in the daily ledger instead of the whole paid amount
 * being pinned to the purchase-order date.
 */
#[ORM\Table(name: PurchasePayment::TABLE_NAME)]
#[ORM\Entity(repositoryClass: PurchasePaymentRepository::class)]
class PurchasePayment
{
    final public const string TABLE_NAME = 'purchase_payment';

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\ManyToOne(targetEntity: Purchase::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(name: 'purchase_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Purchase $purchase = null;

    #[ORM\Column(name: 'payment_date', type: Types::DATE_MUTABLE)]
    private ?DateTimeInterface $paymentDate = null;

    #[ORM\Column(name: 'amount', type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount = '0';

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

    public function getPaymentDate(): ?DateTimeInterface
    {
        return $this->paymentDate;
    }

    public function setPaymentDate(?DateTimeInterface $paymentDate): self
    {
        $this->paymentDate = $paymentDate;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;

        return $this;
    }
}
