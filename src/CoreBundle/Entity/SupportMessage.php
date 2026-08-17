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
use SolidInvoice\CoreBundle\Repository\SupportMessageRepository;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One thing somebody said on a ticket.
 *
 * Whether it came from the platform owner is stored on the row rather than
 * worked out from the author's roles when it is displayed. Roles change - a
 * member could be made an admin next year - and that must not silently rewrite
 * who said what a year ago.
 */
#[ORM\Table(name: SupportMessage::TABLE_NAME)]
#[ORM\Entity(repositoryClass: SupportMessageRepository::class)]
class SupportMessage
{
    final public const string TABLE_NAME = 'support_message';

    use TimeStampable;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\ManyToOne(targetEntity: SupportTicket::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'ticket_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?SupportTicket $ticket = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /** Who it was from, kept readable after the account is gone. */
    #[ORM\Column(name: 'author_name', type: Types::STRING, length: 191, nullable: true)]
    private ?string $authorName = null;

    #[ORM\Column(name: 'from_owner', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $fromOwner = false;

    #[ORM\Column(name: 'body', type: Types::TEXT)]
    private string $body = '';

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getTicket(): ?SupportTicket
    {
        return $this->ticket;
    }

    public function setTicket(?SupportTicket $ticket): self
    {
        $this->ticket = $ticket;

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): self
    {
        $this->author = $author;

        if ($author instanceof User) {
            $name = trim(($author->getFirstName() ?? '') . ' ' . ($author->getLastName() ?? ''));
            $this->authorName = $name === '' ? $author->getEmail() : $name;
        }

        return $this;
    }

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    public function setAuthorName(?string $authorName): self
    {
        $this->authorName = $authorName;

        return $this;
    }

    public function isFromOwner(): bool
    {
        return $this->fromOwner;
    }

    public function setFromOwner(bool $fromOwner): self
    {
        $this->fromOwner = $fromOwner;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = trim($body);

        return $this;
    }
}
