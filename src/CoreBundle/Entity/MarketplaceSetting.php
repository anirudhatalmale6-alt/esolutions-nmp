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
use SolidInvoice\CoreBundle\Repository\MarketplaceSettingRepository;
use SolidInvoice\CoreBundle\Traits\Entity\CompanyAware;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A company's opt-in settings for the public Marketplace. Each business decides
 * whether its Tally stock is listed for buyers to search, and the WhatsApp number
 * buyers are handed when they press "Chat Now". One row per company.
 */
#[ORM\Table(name: MarketplaceSetting::TABLE_NAME)]
#[ORM\UniqueConstraint(name: 'uniq_marketplace_setting_company', columns: ['company_id'])]
#[ORM\Entity(repositoryClass: MarketplaceSettingRepository::class)]
class MarketplaceSetting
{
    final public const string TABLE_NAME = 'marketplace_setting';

    use TimeStampable;
    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\Column(name: 'listed', type: Types::BOOLEAN)]
    private bool $listed = false;

    #[ORM\Column(name: 'whatsapp', type: Types::STRING, length: 50, nullable: true)]
    private ?string $whatsapp = null;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function isListed(): bool
    {
        return $this->listed;
    }

    public function setListed(bool $listed): self
    {
        $this->listed = $listed;

        return $this;
    }

    public function getWhatsapp(): ?string
    {
        return $this->whatsapp;
    }

    public function setWhatsapp(?string $whatsapp): self
    {
        $this->whatsapp = $whatsapp;

        return $this;
    }
}
