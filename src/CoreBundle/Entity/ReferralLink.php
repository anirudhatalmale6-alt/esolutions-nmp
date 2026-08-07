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
use SolidInvoice\CoreBundle\Repository\ReferralLinkRepository;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A named referral / sales invite link. Each sales rep gets one: a short code that
 * becomes their personal join URL (e.g. b2bnetwork.ae/join/RASHID). A business can
 * only register through a valid, active link - there is no open public signup - and
 * every company that joins is stamped with the code that brought it in, so the
 * platform owner can see how many businesses each rep referred.
 *
 * This is a PLATFORM-level record (owned by the super-admin), NOT company-scoped, so
 * it deliberately does not use the CompanyAware trait.
 */
#[ORM\Table(name: ReferralLink::TABLE_NAME)]
#[ORM\Entity(repositoryClass: ReferralLinkRepository::class)]
class ReferralLink
{
    final public const string TABLE_NAME = 'referral_link';

    use TimeStampable;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    /**
     * The code that appears in the join URL. Stored upper-case and unique; only
     * letters, digits, dash and underscore so it is safe in a path segment.
     */
    #[ORM\Column(name: 'code', type: Types::STRING, length: 64, unique: true)]
    private string $code = '';

    /** Display name of the sales rep this link belongs to. */
    #[ORM\Column(name: 'rep_name', type: Types::STRING, length: 191)]
    private string $repName = '';

    /** A disabled link stops accepting new signups but keeps its history. */
    #[ORM\Column(name: 'active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'note', type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getRepName(): string
    {
        return $this->repName;
    }

    public function setRepName(string $repName): self
    {
        $this->repName = $repName;

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

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }
}
