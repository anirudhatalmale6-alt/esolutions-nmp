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
use SolidInvoice\CoreBundle\Enum\OrderStatus;
use SolidInvoice\CoreBundle\Repository\StoreOrderRepository;
use SolidInvoice\CoreBundle\Traits\Entity\CompanyAware;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A retail order captured in the MobilesOnline orders portal. The order team
 * (e.g. a remote desk) enters a confirmed WhatsApp order here with the customer
 * and despatch details; the office then prints the 4x6 shipping label, packs
 * and despatches, tracking progress through the {@see OrderStatus} pipeline.
 *
 * Deliberately a single handset per order (matching how the WhatsApp orders come
 * in) to keep the portal lean; multi-line orders can be added later if needed.
 */
#[ORM\Table(name: StoreOrder::TABLE_NAME)]
#[ORM\Entity(repositoryClass: StoreOrderRepository::class)]
class StoreOrder implements Stringable
{
    final public const string TABLE_NAME = 'store_order';

    use TimeStampable;
    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    /** Human-friendly running number, e.g. MO-0001. Unique per company. */
    #[ORM\Column(name: 'order_number', type: Types::STRING, length: 20)]
    private string $orderNumber = '';

    #[ORM\Column(name: 'status', type: Types::STRING, length: 20)]
    private string $status = OrderStatus::New->value;

    #[ORM\Column(name: 'customer_name', type: Types::STRING, length: 255)]
    private string $customerName = '';

    #[ORM\Column(name: 'customer_phone', type: Types::STRING, length: 50)]
    private string $customerPhone = '';

    #[ORM\Column(name: 'customer_whatsapp', type: Types::STRING, length: 50, nullable: true)]
    private ?string $customerWhatsapp = null;

    #[ORM\Column(name: 'address_line', type: Types::TEXT)]
    private string $addressLine = '';

    #[ORM\Column(name: 'area', type: Types::STRING, length: 150, nullable: true)]
    private ?string $area = null;

    #[ORM\Column(name: 'city', type: Types::STRING, length: 150, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(name: 'emirate', type: Types::STRING, length: 100, nullable: true)]
    private ?string $emirate = null;

    #[ORM\Column(name: 'country', type: Types::STRING, length: 100)]
    private string $country = 'United Arab Emirates';

    #[ORM\Column(name: 'model', type: Types::STRING, length: 255)]
    private string $model = '';

    #[ORM\Column(name: 'storage', type: Types::STRING, length: 50, nullable: true)]
    private ?string $storage = null;

    #[ORM\Column(name: 'grade_condition', type: Types::STRING, length: 100, nullable: true)]
    private ?string $condition = null;

    #[ORM\Column(name: 'color', type: Types::STRING, length: 100, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(name: 'quantity', type: Types::INTEGER)]
    private int $quantity = 1;

    #[ORM\Column(name: 'price', type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $price = '0';

    /** 'paid' or 'cod'. */
    #[ORM\Column(name: 'payment_status', type: Types::STRING, length: 20)]
    private string $paymentStatus = 'cod';

    #[ORM\Column(name: 'cod_amount', type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $codAmount = null;

    #[ORM\Column(name: 'courier', type: Types::STRING, length: 150, nullable: true)]
    private ?string $courier = null;

    #[ORM\Column(name: 'tracking_number', type: Types::STRING, length: 150, nullable: true)]
    private ?string $trackingNumber = null;

    #[ORM\Column(name: 'notes', type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function setOrderNumber(string $orderNumber): self
    {
        $this->orderNumber = $orderNumber;
        return $this;
    }

    public function getStatus(): OrderStatus
    {
        return OrderStatus::tryFrom($this->status) ?? OrderStatus::New;
    }

    public function setStatus(OrderStatus $status): self
    {
        $this->status = $status->value;
        return $this;
    }

    public function getCustomerName(): string
    {
        return $this->customerName;
    }

    public function setCustomerName(string $customerName): self
    {
        $this->customerName = $customerName;
        return $this;
    }

    public function getCustomerPhone(): string
    {
        return $this->customerPhone;
    }

    public function setCustomerPhone(string $customerPhone): self
    {
        $this->customerPhone = $customerPhone;
        return $this;
    }

    public function getCustomerWhatsapp(): ?string
    {
        return $this->customerWhatsapp;
    }

    public function setCustomerWhatsapp(?string $customerWhatsapp): self
    {
        $this->customerWhatsapp = $customerWhatsapp;
        return $this;
    }

    public function getAddressLine(): string
    {
        return $this->addressLine;
    }

    public function setAddressLine(string $addressLine): self
    {
        $this->addressLine = $addressLine;
        return $this;
    }

    public function getArea(): ?string
    {
        return $this->area;
    }

    public function setArea(?string $area): self
    {
        $this->area = $area;
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

    public function getEmirate(): ?string
    {
        return $this->emirate;
    }

    public function setEmirate(?string $emirate): self
    {
        $this->emirate = $emirate;
        return $this;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(string $country): self
    {
        $this->country = $country;
        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    public function getStorage(): ?string
    {
        return $this->storage;
    }

    public function setStorage(?string $storage): self
    {
        $this->storage = $storage;
        return $this;
    }

    public function getCondition(): ?string
    {
        return $this->condition;
    }

    public function setCondition(?string $condition): self
    {
        $this->condition = $condition;
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = max(1, $quantity);
        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;
        return $this;
    }

    public function getPaymentStatus(): string
    {
        return $this->paymentStatus;
    }

    public function setPaymentStatus(string $paymentStatus): self
    {
        $this->paymentStatus = $paymentStatus === 'paid' ? 'paid' : 'cod';
        return $this;
    }

    public function isCod(): bool
    {
        return $this->paymentStatus === 'cod';
    }

    public function getCodAmount(): ?string
    {
        return $this->codAmount;
    }

    public function setCodAmount(?string $codAmount): self
    {
        $this->codAmount = $codAmount;
        return $this;
    }

    public function getCourier(): ?string
    {
        return $this->courier;
    }

    public function setCourier(?string $courier): self
    {
        $this->courier = $courier;
        return $this;
    }

    public function getTrackingNumber(): ?string
    {
        return $this->trackingNumber;
    }

    public function setTrackingNumber(?string $trackingNumber): self
    {
        $this->trackingNumber = $trackingNumber;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function __toString(): string
    {
        return $this->orderNumber !== '' ? $this->orderNumber : 'Order';
    }
}
