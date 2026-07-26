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
use SolidInvoice\CoreBundle\Repository\ModelCatalogEntryRepository;
use SolidInvoice\CoreBundle\Traits\Entity\CompanyAware;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One phone-model name in a company's editable model list. This list feeds the
 * "type the model on line 1" suggestion box on invoice / quote line items, so the
 * owner always picks the same spelling and the Sales-by-Model report groups
 * cleanly. Each company owns its own list, seeded from the built-in manufacturer
 * catalogue and then editable from the "Manage model list" page.
 */
#[ORM\Table(name: ModelCatalogEntry::TABLE_NAME)]
#[ORM\UniqueConstraint(name: 'uniq_model_catalog_company_name', columns: ['company_id', 'name'])]
#[ORM\Index(columns: ['company_id'], name: 'idx_model_catalog_company')]
#[ORM\Entity(repositoryClass: ModelCatalogEntryRepository::class)]
class ModelCatalogEntry implements Stringable
{
    final public const string TABLE_NAME = 'model_catalog';

    use TimeStampable;
    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 255)]
    private string $name = '';

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

    public function __toString(): string
    {
        return $this->name;
    }
}
