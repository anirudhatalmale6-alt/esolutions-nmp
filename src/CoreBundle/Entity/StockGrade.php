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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A grade breakdown (AU C, BR, RD, A1, ...) belonging to a {@see StockModel}.
 */
#[ORM\Table(name: StockGrade::TABLE_NAME)]
#[ORM\Entity]
class StockGrade implements Stringable
{
    final public const string TABLE_NAME = 'stock_grade';

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\ManyToOne(targetEntity: StockModel::class, inversedBy: 'grades')]
    #[ORM\JoinColumn(name: 'stock_model_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?StockModel $stockModel = null;

    #[ORM\Column(name: 'grade', type: Types::STRING, length: 255)]
    private string $grade = '';

    #[ORM\Column(name: 'quantity', type: Types::INTEGER)]
    private int $quantity = 0;

    #[ORM\Column(name: 'rate', type: Types::DECIMAL, precision: 15, scale: 4)]
    private string $rate = '0';

    #[ORM\Column(name: 'value', type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $value = '0';

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

    public function getGrade(): string
    {
        return $this->grade;
    }

    public function setGrade(string $grade): self
    {
        $this->grade = $grade;

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

    public function getRate(): string
    {
        return $this->rate;
    }

    public function setRate(string $rate): self
    {
        $this->rate = $rate;

        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function __toString(): string
    {
        return $this->grade;
    }
}
