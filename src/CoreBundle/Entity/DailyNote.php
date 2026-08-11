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
use SolidInvoice\CoreBundle\Repository\DailyNoteRepository;
use SolidInvoice\CoreBundle\Traits\Entity\CompanyAware;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The day's scrap pad - the paper notebook the owner keeps beside him, moved
 * into the app.
 *
 * Deliberately one free-text block per day rather than structured fields: what
 * goes in it is prices being quoted, piece counts, running sums, a reminder
 * about a salary. Forcing that into columns would just stop it being used.
 *
 * One note per company per day; opening the daily ledger for a date shows that
 * date's note, and it prints with the ledger.
 */
#[ORM\Table(name: DailyNote::TABLE_NAME)]
#[ORM\UniqueConstraint(name: 'uniq_daily_note_company_date', columns: ['company_id', 'note_date'])]
#[ORM\Entity(repositoryClass: DailyNoteRepository::class)]
class DailyNote
{
    final public const string TABLE_NAME = 'daily_note';

    use TimeStampable;
    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\Column(name: 'note_date', type: Types::DATE_MUTABLE)]
    private ?DateTimeInterface $noteDate = null;

    #[ORM\Column(name: 'body', type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    /** Who last wrote it. Plain name - users can be removed later. */
    #[ORM\Column(name: 'updated_by', type: Types::STRING, length: 191, nullable: true)]
    private ?string $updatedBy = null;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getNoteDate(): ?DateTimeInterface
    {
        return $this->noteDate;
    }

    public function setNoteDate(?DateTimeInterface $noteDate): self
    {
        $this->noteDate = $noteDate;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?string $updatedBy): self
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->body === null || trim($this->body) === '';
    }
}
