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
use SolidInvoice\ClientBundle\Entity\Client;
use SolidInvoice\CoreBundle\Repository\CreditNoteRepository;
use SolidInvoice\CoreBundle\Traits\Entity\CompanyAware;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A customer refund / credit note raised against an invoice. The customer brought
 * goods back and is refunded, either as cash paid back out or as store credit
 * added to their account. This is a money + record entity only; it does NOT touch
 * stock (stock is governed by the Tally import - a returned phone is fixed back
 * into stock or written off to BER in Tally, and the next import reflects it).
 *
 * Amount is stored in MAJOR units (AED, DECIMAL) like Expense/Purchase, so it
 * folds straight into the daily ledger. Cash refunds count as money-out; store
 * credit does not (it is a liability, added to the client's credit balance).
 */
#[ORM\Table(name: CreditNote::TABLE_NAME)]
#[ORM\Entity(repositoryClass: CreditNoteRepository::class)]
class CreditNote
{
    final public const string TABLE_NAME = 'credit_note';

    /**
     * Cash physically refunded to the customer (money-out).
     */
    final public const string TYPE_CASH = 'cash';

    /**
     * Store credit added to the customer's balance for a future order (not cash-out).
     */
    final public const string TYPE_CREDIT = 'credit';

    /**
     * The returned unit was repaired and goes back into sellable stock.
     */
    final public const string DISPOSITION_REPAIRED = 'repaired';

    /**
     * The returned unit is Beyond Economic Repair (written off to BER stock).
     */
    final public const string DISPOSITION_BER = 'ber';

    use TimeStampable;
    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Invoice $invoice = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Client $client = null;

    #[ORM\Column(name: 'credit_date', type: Types::DATE_MUTABLE)]
    private ?DateTimeInterface $creditDate = null;

    #[ORM\Column(name: 'amount', type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount = '0';

    #[ORM\Column(name: 'refund_type', type: Types::STRING, length: 16)]
    private string $refundType = self::TYPE_CASH;

    #[ORM\Column(name: 'disposition', type: Types::STRING, length: 16, nullable: true)]
    private ?string $disposition = null;

    #[ORM\Column(name: 'reference', type: Types::STRING, length: 128, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(name: 'reason', type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): self
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getCreditDate(): ?DateTimeInterface
    {
        return $this->creditDate;
    }

    public function setCreditDate(?DateTimeInterface $creditDate): self
    {
        $this->creditDate = $creditDate;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getRefundType(): string
    {
        return $this->refundType;
    }

    public function setRefundType(string $refundType): self
    {
        $this->refundType = $refundType;

        return $this;
    }

    public function isStoreCredit(): bool
    {
        return $this->refundType === self::TYPE_CREDIT;
    }

    public function getDisposition(): ?string
    {
        return $this->disposition;
    }

    public function setDisposition(?string $disposition): self
    {
        $this->disposition = $disposition;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }
}
