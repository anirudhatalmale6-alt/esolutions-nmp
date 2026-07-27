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
use SolidInvoice\CoreBundle\Repository\SharedModelCatalogEntryRepository;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One phone-model name in the portal-wide shared model list. Unlike
 * {@see ModelCatalogEntry}, this list is NOT scoped to a company: it is a single
 * master list that every vendor's invoice / quote line-item suggestion box reads
 * from, so a spelling curated once by the platform owner helps everyone. Only the
 * Super User (platform owner) can edit it - business admins cannot change or
 * delete it.
 */
#[ORM\Table(name: SharedModelCatalogEntry::TABLE_NAME)]
#[ORM\UniqueConstraint(name: 'uniq_shared_model_catalog_name', columns: ['name'])]
#[ORM\Entity(repositoryClass: SharedModelCatalogEntryRepository::class)]
class SharedModelCatalogEntry implements Stringable
{
    final public const string TABLE_NAME = 'model_catalog_shared';

    use TimeStampable;

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
