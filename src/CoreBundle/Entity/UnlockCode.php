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
use SolidInvoice\CoreBundle\Repository\UnlockCodeRepository;
use SolidInvoice\CoreBundle\Traits\Entity\CompanyAware;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A single IMEI -> SIM unlock code pair, imported from the supplier's unlocking
 * sheets. Deliberately minimal: only the IMEI and its code (which may also be a
 * status such as "SIM Free" or "Locked") are kept - no model, colour or carrier.
 *
 * Backs the public IMEI lookup page where a customer types their IMEI and gets
 * their code back, nothing else.
 */
#[ORM\Table(name: UnlockCode::TABLE_NAME)]
#[ORM\UniqueConstraint(name: 'uniq_unlock_company_imei', columns: ['company_id', 'imei'])]
#[ORM\Index(columns: ['imei'], name: 'idx_unlock_imei')]
#[ORM\Entity(repositoryClass: UnlockCodeRepository::class)]
class UnlockCode implements Stringable
{
    final public const string TABLE_NAME = 'unlock_code';

    use TimeStampable;
    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\Column(name: 'imei', type: Types::STRING, length: 32)]
    private string $imei = '';

    #[ORM\Column(name: 'code', type: Types::STRING, length: 255)]
    private string $code = '';

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getImei(): string
    {
        return $this->imei;
    }

    public function setImei(string $imei): self
    {
        $this->imei = $imei;

        return $this;
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

    public function __toString(): string
    {
        return $this->imei;
    }
}
