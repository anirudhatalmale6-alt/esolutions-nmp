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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SolidInvoice\CoreBundle\Repository\StockModelRepository;
use SolidInvoice\CoreBundle\Traits\Entity\CompanyAware;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A stock item (phone model) imported from the Tally stock summary. Each model
 * holds one or more grade breakdowns (see {@see StockGrade}).
 */
#[ORM\Table(name: StockModel::TABLE_NAME)]
#[ORM\Entity(repositoryClass: StockModelRepository::class)]
class StockModel implements Stringable
{
    final public const string TABLE_NAME = 'stock_model';

    use TimeStampable;
    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 255)]
    private string $name = '';

    #[ORM\Column(name: 'quantity', type: Types::INTEGER)]
    private int $quantity = 0;

    #[ORM\Column(name: 'rate', type: Types::DECIMAL, precision: 15, scale: 4)]
    private string $rate = '0';

    #[ORM\Column(name: 'value', type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $value = '0';

    /**
     * @var Collection<int, StockGrade>
     */
    #[ORM\OneToMany(mappedBy: 'stockModel', targetEntity: StockGrade::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $grades;

    public function __construct()
    {
        $this->grades = new ArrayCollection();
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

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

    /**
     * @return Collection<int, StockGrade>
     */
    public function getGrades(): Collection
    {
        return $this->grades;
    }

    public function addGrade(StockGrade $grade): self
    {
        if (! $this->grades->contains($grade)) {
            $this->grades->add($grade);
            $grade->setStockModel($this);
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
