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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SolidInvoice\CoreBundle\Enum\SupportTicketKind;
use SolidInvoice\CoreBundle\Enum\SupportTicketStatus;
use SolidInvoice\CoreBundle\Repository\SupportTicketRepository;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Something a member wants the platform owner to know about.
 *
 * Until now there was no way to say "this is broken" from inside the product -
 * a member had to already have the owner's number. That works while everybody
 * knows each other and stops working the moment it grows.
 *
 * A PLATFORM-level record on purpose: it deliberately does NOT use the
 * CompanyAware trait, because the whole point is that it crosses from a member's
 * company to the owner's. The company it came from is an ordinary relation, so
 * the owner can see every ticket without the company filter hiding them - the
 * same reason the membership console builds its rows with that filter disabled.
 */
#[ORM\Table(name: SupportTicket::TABLE_NAME)]
#[ORM\Entity(repositoryClass: SupportTicketRepository::class)]
#[ORM\Index(name: 'support_ticket_status_idx', columns: ['status'])]
class SupportTicket
{
    final public const string TABLE_NAME = 'support_ticket';

    use TimeStampable;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    /** The business that raised it. */
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    /**
     * The person who raised it. Nullable so deleting an account does not take
     * the history of what they reported with it - a bug is still a bug after the
     * person who found it has gone.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'raised_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $raisedBy = null;

    /**
     * Copies of the name and e-mail as they were when the ticket was raised, so a
     * ticket still says who sent it after the account is gone or renamed.
     */
    #[ORM\Column(name: 'raised_by_name', type: Types::STRING, length: 191, nullable: true)]
    private ?string $raisedByName = null;

    #[ORM\Column(name: 'raised_by_email', type: Types::STRING, length: 191, nullable: true)]
    private ?string $raisedByEmail = null;

    #[ORM\Column(name: 'subject', type: Types::STRING, length: 191)]
    private string $subject = '';

    #[ORM\Column(name: 'kind', type: Types::STRING, length: 20, options: ['default' => 'problem'])]
    private string $kind = SupportTicketKind::Problem->value;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 20, options: ['default' => 'open'])]
    private string $status = SupportTicketStatus::Open->value;

    /**
     * Set when the OTHER side has written last. Two flags rather than one, so
     * neither side has to guess: the owner's list can show what is waiting on
     * them, and the member's list can show what has been answered.
     */
    #[ORM\Column(name: 'awaiting_owner', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $awaitingOwner = true;

    #[ORM\Column(name: 'unread_by_member', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $unreadByMember = false;

    #[ORM\Column(name: 'last_message_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastMessageAt = null;

    /**
     * @var Collection<int, SupportMessage>
     */
    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: SupportMessage::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['created' => 'ASC'])]
    private Collection $messages;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
    }

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

        return $this;
    }

    public function getRaisedBy(): ?User
    {
        return $this->raisedBy;
    }

    public function setRaisedBy(?User $raisedBy): self
    {
        $this->raisedBy = $raisedBy;

        if ($raisedBy instanceof User) {
            $name = trim(($raisedBy->getFirstName() ?? '') . ' ' . ($raisedBy->getLastName() ?? ''));
            $this->raisedByName = $name === '' ? null : $name;
            $this->raisedByEmail = $raisedBy->getEmail();
        }

        return $this;
    }

    public function getRaisedByName(): ?string
    {
        return $this->raisedByName;
    }

    public function getRaisedByEmail(): ?string
    {
        return $this->raisedByEmail;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = trim($subject);

        return $this;
    }

    public function getKind(): SupportTicketKind
    {
        return SupportTicketKind::fromValue($this->kind);
    }

    public function setKind(SupportTicketKind $kind): self
    {
        $this->kind = $kind->value;

        return $this;
    }

    public function getStatus(): SupportTicketStatus
    {
        return SupportTicketStatus::fromValue($this->status);
    }

    public function setStatus(SupportTicketStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function isOpen(): bool
    {
        return $this->getStatus() !== SupportTicketStatus::Closed;
    }

    public function isAwaitingOwner(): bool
    {
        return $this->awaitingOwner;
    }

    public function isUnreadByMember(): bool
    {
        return $this->unreadByMember;
    }

    public function markReadByMember(): self
    {
        $this->unreadByMember = false;

        return $this;
    }

    public function getLastMessageAt(): ?\DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    /**
     * @return Collection<int, SupportMessage>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    /**
     * Add a message and move the ticket's state to match who wrote it.
     *
     * Doing this here rather than in each controller is deliberate: there are two
     * places that add a message and three flags that have to agree afterwards,
     * and the one time they disagree is the time a member's question sits in a
     * list nobody is looking at.
     */
    public function addMessage(SupportMessage $message): self
    {
        $message->setTicket($this);
        $this->messages->add($message);
        // Gedmo stamps `created` on flush, so it is still null here on a brand-new
        // message - which is exactly when "now" is the right answer anyway.
        $created = $message->getCreated();
        $this->lastMessageAt = $created === null
            ? new \DateTimeImmutable()
            : \DateTimeImmutable::createFromInterface($created);

        if ($message->isFromOwner()) {
            $this->awaitingOwner = false;
            $this->unreadByMember = true;

            // Answering a closed ticket opens it again - otherwise the reply
            // lands somewhere the member has no reason to look.
            if ($this->getStatus() === SupportTicketStatus::Closed) {
                $this->status = SupportTicketStatus::Open->value;
            }
        } else {
            $this->awaitingOwner = true;
            $this->status = SupportTicketStatus::Open->value;
        }

        return $this;
    }
}
