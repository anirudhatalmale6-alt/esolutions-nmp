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

namespace SolidInvoice\UserBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use SolidInvoice\UserBundle\Enum\UserSettingType;
use SolidInvoice\UserBundle\Repository\UserSettingRepository;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Ulid;

#[ORM\Table(name: UserSetting::TABLE_NAME)]
#[ORM\UniqueConstraint(columns: ['setting_key', 'user_id'])]
#[ORM\Entity(repositoryClass: UserSettingRepository::class)]
#[UniqueEntity(fields: ['key', 'user'])]
class UserSetting implements Stringable
{
    final public const string TABLE_NAME = 'user_settings';

    use TimeStampable;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    /**
     * onDelete: CASCADE matters more than it looks. Nothing holds an inverse
     * collection of these rows, so the ORM never deletes them when the account
     * they belong to is deleted - the database has to. Without it the FK is
     * MySQL's default RESTRICT, and since every sign-in now records which
     * business you were last in (UserSettingType::LastCompany), every active
     * account has a row here and deleting one fails with "Cannot delete or
     * update a parent row".
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'setting_key', type: Types::STRING, length: 125, enumType: UserSettingType::class)]
    private UserSettingType $key;

    #[ORM\Column(name: 'setting_value', type: Types::TEXT, nullable: true)]
    private ?string $value = null;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getKey(): UserSettingType
    {
        return $this->key;
    }

    public function setKey(UserSettingType $key): self
    {
        $this->key = $key;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
