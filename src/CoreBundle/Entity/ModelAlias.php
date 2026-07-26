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
use SolidInvoice\CoreBundle\Repository\ModelAliasRepository;
use SolidInvoice\CoreBundle\Traits\Entity\CompanyAware;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Maps a phone model name as it was TYPED on an invoice line (the "alias") to the
 * single correct model name it should count under (the "canonical") for the
 * Sales-by-Model report.
 *
 * The owner types models slightly differently over time ("Xperia V", "Sony
 * Xperia V"), which would otherwise split one phone into several rows. Merging
 * those aliases onto one canonical name collapses them back into a single model
 * in the report - across past invoices too - without touching the invoices
 * themselves.
 */
#[ORM\Table(name: ModelAlias::TABLE_NAME)]
#[ORM\UniqueConstraint(name: 'uniq_model_alias_company_alias', columns: ['company_id', 'alias'])]
#[ORM\Index(columns: ['company_id'], name: 'idx_model_alias_company')]
#[ORM\Entity(repositoryClass: ModelAliasRepository::class)]
class ModelAlias implements Stringable
{
    final public const string TABLE_NAME = 'model_alias';

    use TimeStampable;
    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\Column(name: 'alias', type: Types::STRING, length: 255)]
    private string $alias = '';

    #[ORM\Column(name: 'canonical', type: Types::STRING, length: 255)]
    private string $canonical = '';

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function setAlias(string $alias): self
    {
        $this->alias = $alias;

        return $this;
    }

    public function getCanonical(): string
    {
        return $this->canonical;
    }

    public function setCanonical(string $canonical): self
    {
        $this->canonical = $canonical;

        return $this;
    }

    public function __toString(): string
    {
        return $this->alias . ' -> ' . $this->canonical;
    }
}
