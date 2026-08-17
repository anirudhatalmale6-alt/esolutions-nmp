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
use SolidInvoice\CoreBundle\Repository\MarketplaceAdRepository;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function trim;

/**
 * A paid classified advert on the Marketplace home page.
 *
 * There are exactly four places for one, above the community feed, and the
 * platform owner decides who is in them - that is what makes them worth paying
 * for. A member with the Classifieds button switched on prepares their own
 * advert here; it stays out of sight until the owner puts it in a slot.
 *
 * A PLATFORM-level record, like SupportTicket: it deliberately does not use the
 * CompanyAware trait, because these are read on a page with no signed-in
 * company at all. The advertiser is an ordinary relation.
 */
#[ORM\Table(name: MarketplaceAd::TABLE_NAME)]
#[ORM\Entity(repositoryClass: MarketplaceAdRepository::class)]
#[ORM\Index(name: 'marketplace_ad_slot_idx', columns: ['slot'])]
class MarketplaceAd
{
    final public const string TABLE_NAME = 'marketplace_ad';

    /** How many adverts fit on the page. */
    final public const int SLOTS = 4;

    use TimeStampable;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    /**
     * Who is advertising. Nullable so removing a business does not leave the
     * page pointing at a row that is no longer there.
     */
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /** The advertiser's name as it was when the advert was written. */
    #[ORM\Column(name: 'business_name', type: Types::STRING, length: 191, nullable: true)]
    private ?string $businessName = null;

    #[ORM\Column(name: 'title', type: Types::STRING, length: 120)]
    private string $title = '';

    #[ORM\Column(name: 'caption', type: Types::STRING, length: 255, nullable: true)]
    private ?string $caption = null;

    /** Where the picture lives, relative to the marketplace media folder. */
    #[ORM\Column(name: 'image_path', type: Types::STRING, length: 255, nullable: true)]
    private ?string $imagePath = null;

    /**
     * Where a tap goes. Empty means the advertiser's WhatsApp, which is what
     * most of them actually want - a buyer with a phone in their hand.
     */
    #[ORM\Column(name: 'link_url', type: Types::STRING, length: 255, nullable: true)]
    private ?string $linkUrl = null;

    /**
     * Which of the four places it holds, or NULL for one that is written but not
     * placed. Assigning a slot IS the approval - there is no second flag to
     * forget to tick.
     */
    #[ORM\Column(name: 'slot', type: Types::SMALLINT, nullable: true)]
    private ?int $slot = null;

    /**
     * Lets the owner pull an advert off the page for a while - a lapsed payment,
     * a picture being redone - without giving its place away.
     */
    #[ORM\Column(name: 'active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): self
    {
        $this->company = $company;

        if ($company instanceof Company) {
            $this->businessName = $company->getName();
        }

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getBusinessName(): ?string
    {
        return $this->businessName;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = trim($title);

        return $this;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function setCaption(?string $caption): self
    {
        $caption = trim((string) $caption);
        $this->caption = $caption === '' ? null : $caption;

        return $this;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): self
    {
        $imagePath = trim((string) $imagePath);
        $this->imagePath = $imagePath === '' ? null : $imagePath;

        return $this;
    }

    public function getLinkUrl(): ?string
    {
        return $this->linkUrl;
    }

    public function setLinkUrl(?string $linkUrl): self
    {
        $linkUrl = trim((string) $linkUrl);
        $this->linkUrl = $linkUrl === '' ? null : $linkUrl;

        return $this;
    }

    public function getSlot(): ?int
    {
        return $this->slot;
    }

    /**
     * A slot outside the four places is no slot at all - the page has nowhere to
     * draw it, so storing it would only hide the advert with no explanation.
     */
    public function setSlot(?int $slot): self
    {
        if ($slot === null || $slot < 1 || $slot > self::SLOTS) {
            $this->slot = null;

            return $this;
        }

        $this->slot = $slot;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    /** Whether this advert is currently on the page. */
    public function isLive(): bool
    {
        return $this->active && $this->slot !== null && $this->imagePath !== null;
    }
}
