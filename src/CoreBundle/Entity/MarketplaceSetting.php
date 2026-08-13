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
 * A company's opt-in settings for showing its stock outside the portal. Two
 * separate channels live here, and a business can use either, both or neither:
 *
 *  - the Marketplace: its Tally stock is searchable by buyers alongside every
 *    other listed business, who reach it on WhatsApp;
 *  - its own public stock page: a private, no-login link of its own stock only,
 *    which it hands to its customers.
 *
 * One row per company.
 */
#[ORM\Table(name: MarketplaceSetting::TABLE_NAME)]
#[ORM\UniqueConstraint(name: 'uniq_marketplace_setting_company', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uniq_marketplace_setting_share_slug', columns: ['share_slug'])]
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

    /** ISO 3166-1 alpha-2 country code (e.g. "AE"), used for the flag and name. */
    #[ORM\Column(name: 'country', type: Types::STRING, length: 2, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(name: 'city', type: Types::STRING, length: 100, nullable: true)]
    private ?string $city = null;

    /**
     * Whether this business publishes its own no-login stock page. Separate from
     * {@see $listed}: a business can hand its own customers a private link
     * without appearing in the Marketplace search, or the other way round.
     */
    #[ORM\Column(name: 'share_stock', type: Types::BOOLEAN, options: ['default' => 0])]
    private bool $shareStock = false;

    /**
     * The address of that page (/inventory/{slug}). Unique across the portal, so
     * one business's link can never land on another's stock.
     */
    #[ORM\Column(name: 'share_slug', type: Types::STRING, length: 60, nullable: true)]
    private ?string $shareSlug = null;

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

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $this->country = $country;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $this->city = $city;

        return $this;
    }

    public function isShareStock(): bool
    {
        return $this->shareStock;
    }

    public function setShareStock(bool $shareStock): self
    {
        $this->shareStock = $shareStock;

        return $this;
    }

    public function getShareSlug(): ?string
    {
        return $this->shareSlug;
    }

    public function setShareSlug(?string $shareSlug): self
    {
        $this->shareSlug = $shareSlug;

        return $this;
    }
}
