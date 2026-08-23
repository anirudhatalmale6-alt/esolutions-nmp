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

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Export\Attribute\ExportIgnore;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use SolidInvoice\UserBundle\Repository\UserRepository;
use SolidWorx\Platform\PlatformBundle\Security\TwoFactor\Traits\UserTwoFactor;
use SolidWorx\Platform\SaasBundle\Trial\TrialUserInterface;

#[ORM\Table(name: User::TABLE_NAME)]
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ExportIgnore]
class User extends \SolidWorx\Platform\PlatformBundle\Model\User implements TrialUserInterface
{
    final public const string TABLE_NAME = 'users';

    use TimeStampable;
    use UserTwoFactor;

    /**
     * @var Collection<int, ApiToken>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: ApiToken::class, cascade: ['persist', 'remove'], fetch: 'EXTRA_LAZY', orphanRemoval: true)]
    private Collection $apiTokens;

    /**
     * @var Collection<int, Company>
     */
    #[ORM\ManyToMany(targetEntity: Company::class, inversedBy: 'users', cascade: ['persist'])]
    private Collection $companies;

    /**
     * When this account confirmed the address we hold for it, by opening the
     * link that was EMAILED to it.
     *
     * The parent's $verified is a different question: it means "this account is
     * activated", and it is set whichever way the link arrived. That was enough
     * while there was one channel. The confirmation link now also goes out over
     * WhatsApp, and somebody who never opened their inbox has proved nothing
     * about their email address - so the two facts are recorded separately
     * rather than one standing in for both.
     *
     * Null on an account confirmed before this was added: the channel was not
     * recorded then, and guessing it here would put a tick against an address
     * nobody has ever answered on.
     */
    #[ORM\Column(name: 'email_verified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $emailVerifiedAt = null;

    /**
     * When this account confirmed its contact number, by opening the link that
     * was sent to it ON WHATSAPP.
     *
     * This is the stronger of the two for a marketplace: an email address costs
     * nothing, a working number that answers is the thing the owner actually
     * wants before letting a stranger trade.
     */
    #[ORM\Column(name: 'mobile_verified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $mobileVerifiedAt = null;

    /**
     * @deprecated This should not be used anymore. Remove once all usages are gone.
     */
    public ?string $plainPassword = null;

    public function __construct()
    {
        parent::__construct();
        $this->apiTokens = new ArrayCollection();
        $this->companies = new ArrayCollection();
    }

    /**
     * @return Collection<int, ApiToken>
     */
    public function getApiTokens(): Collection
    {
        return $this->apiTokens;
    }

    /**
     * @param Collection<int, ApiToken> $apiTokens
     */
    public function setApiTokens(Collection $apiTokens): static
    {
        $this->apiTokens = $apiTokens;

        return $this;
    }

    /**
     * @return Collection<int, Company>
     */
    public function getCompanies(): Collection
    {
        return $this->companies;
    }

    public function addCompany(Company $company): static
    {
        if (! $this->companies->contains($company)) {
            $this->companies->add($company);
        }

        return $this;
    }

    public function removeCompany(Company $company): static
    {
        if ($this->companies->contains($company)) {
            $this->companies->removeElement($company);
        }

        return $this;
    }

    public function getEmailVerifiedAt(): ?DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?DateTimeImmutable $emailVerifiedAt): static
    {
        $this->emailVerifiedAt = $emailVerifiedAt;

        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt instanceof DateTimeImmutable;
    }

    public function getMobileVerifiedAt(): ?DateTimeImmutable
    {
        return $this->mobileVerifiedAt;
    }

    public function setMobileVerifiedAt(?DateTimeImmutable $mobileVerifiedAt): static
    {
        $this->mobileVerifiedAt = $mobileVerifiedAt;

        return $this;
    }

    public function isMobileVerified(): bool
    {
        return $this->mobileVerifiedAt instanceof DateTimeImmutable;
    }

    /**
     * An account that is activated but has no channel recorded against it: it
     * confirmed before the two were told apart. Shown as its own state rather
     * than as either a tick or a cross, both of which would be a claim nobody
     * can back up.
     */
    public function isVerifiedWithoutChannel(): bool
    {
        return $this->isVerified() && ! $this->isEmailVerified() && ! $this->isMobileVerified();
    }
}
