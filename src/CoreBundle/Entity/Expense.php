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
use SolidInvoice\CoreBundle\Repository\ExpenseRepository;
use SolidInvoice\CoreBundle\Traits\Entity\CompanyAware;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A payout / operating expense (rent, salaries, utilities, etc). This is a
 * money-out record only; it does not affect stock or suppliers, and feeds the
 * daily ledger "money out" figure.
 */
#[ORM\Table(name: Expense::TABLE_NAME)]
#[ORM\Entity(repositoryClass: ExpenseRepository::class)]
class Expense
{
    final public const string TABLE_NAME = 'expense';

    use TimeStampable;
    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\Column(name: 'expense_date', type: Types::DATE_MUTABLE)]
    private ?DateTimeInterface $expenseDate = null;

    #[ORM\Column(name: 'category', type: Types::STRING, length: 128)]
    private string $category = '';

    #[ORM\Column(name: 'payee', type: Types::STRING, length: 191, nullable: true)]
    private ?string $payee = null;

    #[ORM\Column(name: 'amount', type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount = '0';

    #[ORM\Column(name: 'description', type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getExpenseDate(): ?DateTimeInterface
    {
        return $this->expenseDate;
    }

    public function setExpenseDate(?DateTimeInterface $expenseDate): self
    {
        $this->expenseDate = $expenseDate;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getPayee(): ?string
    {
        return $this->payee;
    }

    public function setPayee(?string $payee): self
    {
        $this->payee = $payee;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }
}
