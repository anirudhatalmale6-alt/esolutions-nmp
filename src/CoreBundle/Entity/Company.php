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

use const PHP_URL_HOST;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SolidInvoice\ClientBundle\Entity\Address;
use SolidInvoice\ClientBundle\Entity\Client;
use SolidInvoice\ClientBundle\Entity\Contact;
use SolidInvoice\ClientBundle\Entity\Credit;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\InvoiceBundle\Entity\InvoiceReminder;
use SolidInvoice\InvoiceBundle\Entity\Line as InvoieLine;
use SolidInvoice\InvoiceBundle\Entity\RecurringInvoice;
use SolidInvoice\NotificationBundle\Entity\TransportSetting;
use SolidInvoice\NotificationBundle\Entity\UserNotification;
use SolidInvoice\PaymentBundle\Entity\Payment;
use SolidInvoice\PaymentBundle\Entity\PaymentMethod;
use SolidInvoice\QuoteBundle\Entity\Line as QuoteLine;
use SolidInvoice\QuoteBundle\Entity\Quote;
use SolidInvoice\SettingsBundle\Entity\Setting;
use SolidInvoice\TaxBundle\Entity\Tax;
use SolidInvoice\UserBundle\Entity\ApiToken;
use SolidInvoice\UserBundle\Entity\ApiTokenHistory;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Entity\UserInvitation;
use SolidWorx\Platform\PlatformBundle\Feature\SubscribableInterface;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;
use function function_exists;
use function idn_to_ascii;
use function is_string;
use function parse_url;
use function preg_replace;
use function rtrim;
use function str_contains;
use function strtolower;
use function trim;

#[ORM\Table(name: Company::TABLE_NAME)]
#[ORM\Entity(repositoryClass: CompanyRepository::class)]
#[UniqueEntity(fields: ['customDomain'], ignoreNull: true)]
class Company implements Stringable, SubscribableInterface
{
    final public const string TABLE_NAME = 'companies';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank()]
    private string $name;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'companies')]
    private Collection $users;

    #[Assert\NotBlank()]
    public ?string $currency = '';

    #[ORM\Column(name: 'custom_domain', type: Types::STRING, length: 253, unique: true, nullable: true)]
    #[Assert\Length(max: 253)]
    #[Assert\Hostname(requireTld: true)]
    private ?string $customDomain = null;

    // --- Membership / subscription state (managed via MembershipManager) ---

    /**
     * The company's membership tier: 'none' | 'basic' | 'premium'.
     * Stored as a plain string (resolved to {@see MembershipPlan}) so a stray
     * value can never blow up hydration.
     */
    #[ORM\Column(name: 'membership_plan', type: Types::STRING, length: 20, options: ['default' => 'none'])]
    private string $membershipPlan = 'none';

    /**
     * When the current membership lapses. NULL = no expiry (lifetime access,
     * e.g. a permanent complimentary account). A paid annual plan sets this to
     * roughly one year out.
     */
    #[ORM\Column(name: 'membership_expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $membershipExpiresAt = null;

    /**
     * TRUE when the platform owner granted this plan for free (no Stripe charge).
     * Purely informational - access is still decided by plan + expiry - but it
     * tells the super-user panel "this one is comped, don't expect a renewal".
     */
    #[ORM\Column(name: 'membership_complimentary', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $membershipComplimentary = false;

    /**
     * TRUE once the platform owner has vetted this company. Verification is
     * required before a company can hold the Premium tier.
     */
    #[ORM\Column(name: 'verified', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $verified = false;

    /**
     * TRUE when the platform owner has handed this company the Marketplace by
     * name, without putting it on Premium. The Marketplace is normally a Premium
     * sales channel; this lets the owner let a business in on its own merits - a
     * good supplier, someone being brought on board - and take it back later,
     * without touching what they pay or when it runs out.
     *
     * Grants the Marketplace only. The Online Store, Orders and Unlock Codes stay
     * behind Premium.
     */
    #[ORM\Column(name: 'marketplace_access', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $marketplaceAccess = false;

    /**
     * Whether this business's documents move its stock.
     *
     * OFF means nothing changed: quantities only move when somebody uploads the
     * Tally sheet, exactly as before. ON means invoices take stock out and
     * purchases put it in, and the system becomes the thing that knows what is
     * on the shelf.
     *
     * Deliberately off to begin with, and set per business rather than for the
     * whole portal. A vendor who runs on Tally can carry on doing so, one who
     * has never used Tally can work entirely in here, and either can switch when
     * they are ready instead of on the day an update lands.
     */
    #[ORM\Column(name: 'live_stock', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $liveStock = false;

    /**
     * Where the business trades from. Asked on the second page of sign-up, so
     * the super-user panel has something real to look at when deciding whether
     * to hand out the trusted badge, instead of a name and an email address.
     */
    #[ORM\Column(name: 'city', type: Types::STRING, length: 100, nullable: true)]
    private ?string $city = null;

    /**
     * ISO 3166-1 alpha-2, e.g. AE. Stored as a code rather than a typed-in
     * country name so two businesses in the same place always match.
     */
    #[ORM\Column(name: 'country', type: Types::STRING, length: 2, nullable: true)]
    private ?string $country = null;

    /**
     * The business contact number in full international form (+971...). This is
     * the one thing the owner most wants before trusting a stranger, so it is
     * asked at sign-up rather than left for the profile page nobody visits.
     */
    #[ORM\Column(name: 'contact_number', type: Types::STRING, length: 32, nullable: true)]
    private ?string $contactNumber = null;

    /**
     * TRUE once somebody has actually reached that number and had a reply.
     *
     * Deliberately separate from {@see $verified}: the badge is a judgement about
     * the whole business, this is a plain fact about one phone number. Ticked by
     * hand today; if a paid SMS provider is ever wired in, it sets the same flag.
     */
    #[ORM\Column(name: 'contact_verified', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $contactVerified = false;

    /**
     * Paths to the identity documents uploaded for the trusted badge, relative to
     * var/verification. NEVER under public/ - these are passports and national
     * IDs, and they are served only through a controller that checks the reader
     * is the platform owner.
     */
    #[ORM\Column(name: 'id_front_path', type: Types::STRING, length: 255, nullable: true)]
    private ?string $idFrontPath = null;

    #[ORM\Column(name: 'id_back_path', type: Types::STRING, length: 255, nullable: true)]
    private ?string $idBackPath = null;

    #[ORM\Column(name: 'passport_path', type: Types::STRING, length: 255, nullable: true)]
    private ?string $passportPath = null;

    /**
     * When the business last sent documents in. Drives the "waiting for review"
     * state - a company with documents and no badge yet is a queue item, not a
     * company that never bothered.
     */
    #[ORM\Column(name: 'verification_submitted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verificationSubmittedAt = null;

    /**
     * The referral / sales link code that brought this company onto the platform
     * (see ReferralLink). NULL for companies that predate the referral system or
     * were created directly by the platform owner. Used to attribute a signup to
     * a sales rep and to count how many businesses each rep referred.
     */
    #[ORM\Column(name: 'referred_by_code', type: Types::STRING, length: 64, nullable: true)]
    private ?string $referredByCode = null;

    /**
     * Snapshot of the rep's display name at signup time, so the super-user panel
     * can show "Referred by: Rashid" even if the link is later renamed or removed.
     */
    #[ORM\Column(name: 'referred_by_name', type: Types::STRING, length: 191, nullable: true)]
    private ?string $referredByName = null;

    // Related entities: Only added here to enable orphan removal
    /**
     * @var Collection<int, ApiTokenHistory>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: ApiTokenHistory::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $apiTokenHistories;

    /**
     * @var Collection<int, Tax>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Tax::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $taxes;

    /**
     * @var Collection<int, Address>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Address::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $addresses;

    /**
     * @var Collection<int, Client>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Client::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $clients;

    /**
     * @var Collection<int, Contact>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Contact::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $contacts;

    /**
     * @var Collection<int, Credit>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Credit::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $credit;

    /**
     * @var Collection<int, UserInvitation>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: UserInvitation::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $userInvitations;

    /**
     * @var Collection<int, ApiToken>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: ApiToken::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $apiTokens;

    /**
     * @var Collection<int, Setting>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Setting::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $settings;

    /**
     * @var Collection<int, Quote>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Quote::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $quotes;

    /**
     * @var Collection<int, QuoteLine>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: QuoteLine::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $quoteLines;

    /**
     * @var Collection<int, PaymentMethod>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: PaymentMethod::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $paymentMethods;

    /**
     * @var Collection<int, Payment>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Payment::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $payments;

    /**
     * @var Collection<int, UserNotification>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: UserNotification::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $userNotifications;

    /**
     * @var Collection<int, TransportSetting>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: TransportSetting::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $transportSettings;

    /**
     * @var Collection<int, Invoice>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Invoice::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $invoices;

    /**
     * @var Collection<int, RecurringInvoice>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: RecurringInvoice::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $recurringInvoices;

    /**
     * @var Collection<int, InvoieLine>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: InvoieLine::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $invoiceLines;

    /**
     * @var Collection<int, InvoiceReminder>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: InvoiceReminder::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $invoiceReminders;

    public function __construct()
    {
        $this->apiTokenHistories = new ArrayCollection();
        $this->taxes = new ArrayCollection();
        $this->addresses = new ArrayCollection();
        $this->clients = new ArrayCollection();
        $this->contacts = new ArrayCollection();
        $this->credit = new ArrayCollection();
        $this->userInvitations = new ArrayCollection();
        $this->apiTokens = new ArrayCollection();
        $this->settings = new ArrayCollection();
        $this->quotes = new ArrayCollection();
        $this->quoteLines = new ArrayCollection();
        $this->paymentMethods = new ArrayCollection();
        $this->payments = new ArrayCollection();
        $this->userNotifications = new ArrayCollection();
        $this->transportSettings = new ArrayCollection();
        $this->invoices = new ArrayCollection();
        $this->recurringInvoices = new ArrayCollection();
        $this->invoiceLines = new ArrayCollection();
        $this->users = new ArrayCollection();
        $this->invoiceReminders = new ArrayCollection();
        $this->id = new Ulid();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): self
    {
        if (! $this->users->contains($user)) {
            $this->users->add($user);
            $user->addCompany($this);
        }

        return $this;
    }

    public function removeUser(User $user): self
    {
        if ($this->users->removeElement($user)) {
            $user->removeCompany($this);
        }

        return $this;
    }

    public function getCustomDomain(): ?string
    {
        return $this->customDomain;
    }

    public function setCustomDomain(?string $customDomain): self
    {
        $this->customDomain = self::normalizeCustomDomain($customDomain);

        return $this;
    }

    public function getMembershipPlan(): string
    {
        return $this->membershipPlan;
    }

    public function setMembershipPlan(string $membershipPlan): self
    {
        $this->membershipPlan = $membershipPlan;

        return $this;
    }

    public function getMembershipExpiresAt(): ?\DateTimeImmutable
    {
        return $this->membershipExpiresAt;
    }

    public function setMembershipExpiresAt(?\DateTimeImmutable $membershipExpiresAt): self
    {
        $this->membershipExpiresAt = $membershipExpiresAt;

        return $this;
    }

    public function isMembershipComplimentary(): bool
    {
        return $this->membershipComplimentary;
    }

    public function setMembershipComplimentary(bool $membershipComplimentary): self
    {
        $this->membershipComplimentary = $membershipComplimentary;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function setVerified(bool $verified): self
    {
        $this->verified = $verified;

        return $this;
    }

    public function hasMarketplaceAccess(): bool
    {
        return $this->marketplaceAccess;
    }

    public function setMarketplaceAccess(bool $marketplaceAccess): self
    {
        $this->marketplaceAccess = $marketplaceAccess;

        return $this;
    }

    public function hasLiveStock(): bool
    {
        return $this->liveStock;
    }

    public function setLiveStock(bool $liveStock): self
    {
        $this->liveStock = $liveStock;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $this->city = $this->clean($city);

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $country = $this->clean($country);
        $this->country = $country === null ? null : strtoupper($country);

        return $this;
    }

    public function getContactNumber(): ?string
    {
        return $this->contactNumber;
    }

    public function setContactNumber(?string $contactNumber): self
    {
        $this->contactNumber = $this->clean($contactNumber);

        return $this;
    }

    public function isContactVerified(): bool
    {
        return $this->contactVerified;
    }

    public function setContactVerified(bool $contactVerified): self
    {
        $this->contactVerified = $contactVerified;

        return $this;
    }

    public function getIdFrontPath(): ?string
    {
        return $this->idFrontPath;
    }

    public function setIdFrontPath(?string $idFrontPath): self
    {
        $this->idFrontPath = $this->clean($idFrontPath);

        return $this;
    }

    public function getIdBackPath(): ?string
    {
        return $this->idBackPath;
    }

    public function setIdBackPath(?string $idBackPath): self
    {
        $this->idBackPath = $this->clean($idBackPath);

        return $this;
    }

    public function getPassportPath(): ?string
    {
        return $this->passportPath;
    }

    public function setPassportPath(?string $passportPath): self
    {
        $this->passportPath = $this->clean($passportPath);

        return $this;
    }

    public function getVerificationSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->verificationSubmittedAt;
    }

    public function setVerificationSubmittedAt(?\DateTimeImmutable $verificationSubmittedAt): self
    {
        $this->verificationSubmittedAt = $verificationSubmittedAt;

        return $this;
    }

    /**
     * Has this business sent in anything at all for the trusted badge?
     */
    public function hasVerificationDocuments(): bool
    {
        return $this->idFrontPath !== null || $this->idBackPath !== null || $this->passportPath !== null;
    }

    /**
     * Documents are in and nobody has judged them yet - the queue the owner works
     * through. A company that is already verified is not waiting for anything.
     */
    public function isAwaitingVerification(): bool
    {
        return ! $this->verified && $this->hasVerificationDocuments();
    }

    /**
     * An empty text box and a box full of spaces both mean "not given". Storing
     * '' would make "have we got a city?" answer yes.
     */
    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function getReferredByCode(): ?string
    {
        return $this->referredByCode;
    }

    public function setReferredByCode(?string $referredByCode): self
    {
        $this->referredByCode = $referredByCode;

        return $this;
    }

    public function getReferredByName(): ?string
    {
        return $this->referredByName;
    }

    public function setReferredByName(?string $referredByName): self
    {
        $this->referredByName = $referredByName;

        return $this;
    }

    public static function normalizeCustomDomain(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $value = $host;
            }
        }

        // strip path / query / fragment if any leaked in
        $value = preg_replace('~[/?#].*$~', '', $value) ?? $value;
        $value = preg_replace('~:\d+$~', '', $value) ?? $value;
        $value = rtrim($value, '.');
        $value = strtolower($value);

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($value);
            if (is_string($ascii) && $ascii !== '') {
                $value = $ascii;
            }
        }

        return $value === '' ? null : $value;
    }
}
