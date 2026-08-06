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
use SolidInvoice\CoreBundle\Repository\CustomerReceiptRepository;
use SolidInvoice\CoreBundle\Traits\Entity\CompanyAware;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A standalone "money in" receipt: a payment received from a customer that is NOT
 * tied to a specific B2B invoice - e.g. a debtor clearing an old balance, or cash
 * taken over the counter. It feeds the daily ledger "money in" figure and reduces
 * that customer's outstanding balance, so payments no longer have to be written by
 * hand. Amount is stored in MAJOR units (like Expense / CreditNote).
 */
#[ORM\Table(name: CustomerReceipt::TABLE_NAME)]
#[ORM\Entity(repositoryClass: CustomerReceiptRepository::class)]
class CustomerReceipt
{
    final public const string TABLE_NAME = 'customer_receipt';

    use TimeStampable;
    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    /**
     * The customer who paid. Optional so an ad-hoc cash receipt can be recorded
     * without a client record; when set, the payment reduces that client's
     * outstanding balance.
     */
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Client $client = null;

    /** Free-text payer name, used when no client is linked. */
    #[ORM\Column(name: 'payer_name', type: Types::STRING, length: 191, nullable: true)]
    private ?string $payerName = null;

    #[ORM\Column(name: 'receipt_date', type: Types::DATE_MUTABLE)]
    private ?DateTimeInterface $receiptDate = null;

    #[ORM\Column(name: 'amount', type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount = '0';

    #[ORM\Column(name: 'method', type: Types::STRING, length: 32)]
    private string $method = 'Cash';

    #[ORM\Column(name: 'reference', type: Types::STRING, length: 128, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(name: 'note', type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    public function getId(): ?Ulid
    {
        return $this->id;
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

    public function getPayerName(): ?string
    {
        return $this->payerName;
    }

    public function setPayerName(?string $payerName): self
    {
        $this->payerName = $payerName;

        return $this;
    }

    /** The display name of who paid: the linked client, else the free-text payer. */
    public function getPayerLabel(): string
    {
        return $this->client?->getName() ?? ($this->payerName ?? '—');
    }

    public function getReceiptDate(): ?DateTimeInterface
    {
        return $this->receiptDate;
    }

    public function setReceiptDate(?DateTimeInterface $receiptDate): self
    {
        $this->receiptDate = $receiptDate;

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

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): self
    {
        $this->method = $method;

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
