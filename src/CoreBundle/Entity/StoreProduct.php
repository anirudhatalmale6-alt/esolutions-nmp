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
use SolidInvoice\CoreBundle\Repository\StoreProductRepository;
use SolidInvoice\CoreBundle\Traits\Entity\CompanyAware;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A phone listed on the public MobilesOnline storefront. This is a SEPARATE
 * catalogue from the internal (Tally) wholesale stock - the shop owner curates
 * only the ~20-25 hot models here, uploaded from their own product Excel sheet,
 * and controls the customer-facing prices, copy, photos and visibility.
 */
#[ORM\Table(name: StoreProduct::TABLE_NAME)]
#[ORM\Entity(repositoryClass: StoreProductRepository::class)]
class StoreProduct implements Stringable
{
    final public const string TABLE_NAME = 'store_product';

    use TimeStampable;
    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    /**
     * A short, stable code the owner assigns (e.g. IP15PM-256). Used to match a
     * row on re-upload so prices/stock update in place instead of duplicating,
     * and so a manually uploaded photo survives a re-upload of the sheet.
     */
    #[ORM\Column(name: 'sku', type: Types::STRING, length: 100)]
    private string $sku = '';

    #[ORM\Column(name: 'make', type: Types::STRING, length: 100)]
    private string $make = '';

    #[ORM\Column(name: 'model', type: Types::STRING, length: 255)]
    private string $model = '';

    #[ORM\Column(name: 'storage', type: Types::STRING, length: 50, nullable: true)]
    private ?string $storage = null;

    #[ORM\Column(name: 'color', type: Types::STRING, length: 100, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(name: 'grade_condition', type: Types::STRING, length: 100, nullable: true)]
    private ?string $condition = null;

    #[ORM\Column(name: 'key_specs', type: Types::STRING, length: 500, nullable: true)]
    private ?string $keySpecs = null;

    #[ORM\Column(name: 'description', type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'regular_price', type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $regularPrice = '0';

    #[ORM\Column(name: 'sale_price', type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $salePrice = null;

    #[ORM\Column(name: 'featured', type: Types::BOOLEAN)]
    private bool $featured = false;

    #[ORM\Column(name: 'in_stock', type: Types::BOOLEAN)]
    private bool $inStock = true;

    /**
     * Web path (relative to the public root, e.g. "uploads/products/xyz.jpg") of
     * the photo the owner uploads after import. Null until they upload one, in
     * which case the storefront shows a clean placeholder.
     */
    #[ORM\Column(name: 'image_path', type: Types::STRING, length: 255, nullable: true)]
    private ?string $imagePath = null;

    #[ORM\Column(name: 'position', type: Types::INTEGER)]
    private int $position = 0;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): self
    {
        $this->sku = $sku;

        return $this;
    }

    public function getMake(): string
    {
        return $this->make;
    }

    public function setMake(string $make): self
    {
        $this->make = $make;

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

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;

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

    public function getKeySpecs(): ?string
    {
        return $this->keySpecs;
    }

    public function setKeySpecs(?string $keySpecs): self
    {
        $this->keySpecs = $keySpecs;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getRegularPrice(): string
    {
        return $this->regularPrice;
    }

    public function setRegularPrice(string $regularPrice): self
    {
        $this->regularPrice = $regularPrice;

        return $this;
    }

    public function getSalePrice(): ?string
    {
        return $this->salePrice;
    }

    public function setSalePrice(?string $salePrice): self
    {
        $this->salePrice = $salePrice;

        return $this;
    }

    /**
     * The price a customer actually pays: the sale price when it is set and is a
     * genuine reduction, otherwise the regular price.
     */
    public function getEffectivePrice(): string
    {
        if ($this->salePrice !== null && $this->salePrice !== '' && (float) $this->salePrice > 0
            && (float) $this->salePrice < (float) $this->regularPrice) {
            return $this->salePrice;
        }

        return $this->regularPrice;
    }

    public function isOnSale(): bool
    {
        return $this->salePrice !== null && $this->salePrice !== '' && (float) $this->salePrice > 0
            && (float) $this->salePrice < (float) $this->regularPrice;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(bool $featured): self
    {
        $this->featured = $featured;

        return $this;
    }

    public function isInStock(): bool
    {
        return $this->inStock;
    }

    public function setInStock(bool $inStock): self
    {
        $this->inStock = $inStock;

        return $this;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): self
    {
        $this->imagePath = $imagePath;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function __toString(): string
    {
        return trim($this->make . ' ' . $this->model);
    }
}
