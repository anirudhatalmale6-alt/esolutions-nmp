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

namespace SolidInvoice\CoreBundle\Membership;

use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Stock\OpeningStock;

/**
 * Single source of truth for "what can this company do" as far as membership
 * goes. Everything (menu locking, server-side gating, the super-user panel and
 * later the Stripe checkout) asks this service rather than poking at the
 * Company columns directly, so the rules live in exactly one place.
 */
final class MembershipManager
{
    public function __construct(
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly OpeningStock $openingStock,
    ) {
    }

    public function planFor(Company $company): MembershipPlan
    {
        return MembershipPlan::fromValue($company->getMembershipPlan());
    }

    /**
     * A membership is "active" when the company is on a paid tier and it has not
     * lapsed. A NULL expiry means no expiry (lifetime / permanent comp).
     */
    public function isActive(Company $company): bool
    {
        if (! $this->planFor($company)->isPaid()) {
            return false;
        }

        $expiresAt = $company->getMembershipExpiresAt();

        return $expiresAt === null || $expiresAt >= new \DateTimeImmutable();
    }

    /**
     * The plan actually in force right now. A lapsed paid plan collapses to None,
     * so gating never has to re-check the expiry itself.
     */
    public function effectivePlan(Company $company): MembershipPlan
    {
        return $this->isActive($company) ? $this->planFor($company) : MembershipPlan::None;
    }

    /**
     * Whether the two public sales channels (Marketplace + Online Store) are
     * unlocked for this company. Premium only, and only while active.
     */
    public function hasSalesChannels(Company $company): bool
    {
        return $this->effectivePlan($company)->unlocksSalesChannels();
    }

    /**
     * Whether this company may use the Marketplace.
     *
     * Premium unlocks it as one of the sales channels, but the platform owner can
     * also hand it to a business by name from the membership console without
     * moving it onto Premium. Either route is enough - but a plan that has lapsed
     * is not, so a business still has to be a paid-up member of the portal.
     */
    public function hasMarketplaceAccess(Company $company): bool
    {
        if ($this->hasSalesChannels($company)) {
            return true;
        }

        return $company->hasMarketplaceAccess() && $this->isActive($company);
    }

    public function isVerified(Company $company): bool
    {
        return $company->isVerified();
    }

    // ---- Current-company convenience (safe on pages with no company context) ----

    public function currentCompany(): ?Company
    {
        $companyId = $this->companySelector->getCompany();

        return $companyId !== null ? $this->companyRepository->find($companyId) : null;
    }

    /**
     * Does the company the user is currently working in have the sales channels?
     * Returns false when there is no company context (e.g. public pages), which
     * is the safe default for gating.
     */
    public function currentHasSalesChannels(): bool
    {
        $company = $this->currentCompany();

        return $company !== null && $this->hasSalesChannels($company);
    }

    /**
     * Can the company the user is currently working in use the Marketplace? True
     * on Premium, and also when the platform owner has granted it by name. The
     * sidebar asks this so a business that has been given the Marketplace is not
     * still told to upgrade for something it already has.
     */
    public function currentHasMarketplaceAccess(): bool
    {
        $company = $this->currentCompany();

        return $company !== null && $this->hasMarketplaceAccess($company);
    }

    // ---- Mutations (used by the super-user panel; Stripe checkout later) ----

    /**
     * Put a company on a plan with an explicit expiry (NULL = no expiry).
     */
    public function grant(Company $company, MembershipPlan $plan, ?\DateTimeImmutable $expiresAt, bool $complimentary): void
    {
        $company
            ->setMembershipPlan($plan->value)
            ->setMembershipExpiresAt($expiresAt)
            ->setMembershipComplimentary($complimentary);

        $this->entityManager->flush();
    }

    /**
     * Grant/renew a plan for one year from today. Used both for a fresh annual
     * activation and for a renewal (Stripe or manual). Complimentary just records
     * that no charge was taken.
     */
    public function activateAnnual(Company $company, MembershipPlan $plan, bool $complimentary): void
    {
        $this->grant($company, $plan, new \DateTimeImmutable('+1 year'), $complimentary);
    }

    public function setVerified(Company $company, bool $verified): void
    {
        $company->setVerified($verified);

        $this->entityManager->flush();
    }

    /**
     * Hand a company the Marketplace, or take it back, without touching its plan.
     */
    public function setMarketplaceAccess(Company $company, bool $marketplaceAccess): void
    {
        $company->setMarketplaceAccess($marketplaceAccess);

        $this->entityManager->flush();
    }

    /**
     * Turn live stock tracking on or off for one business.
     *
     * Switching it ON is the moment its quantities stop coming from Tally and
     * start being kept by the system, so whatever it holds right now is written
     * into the ledger as the opening figure. Without that, every quantity would
     * start life as a number with nothing behind it, and the first person to ask
     * "why does this say twelve" would get no answer.
     *
     * Switching it OFF simply stops documents moving stock. Nothing already
     * recorded is touched - the history stands, and turning it back on picks up
     * where it left off.
     */
    public function setLiveStock(Company $company, bool $liveStock): void
    {
        $wasOn = $company->hasLiveStock();

        $company->setLiveStock($liveStock);

        if ($liveStock && ! $wasOn) {
            $this->openingStock->recordFor($company);
        }

        $this->entityManager->flush();
    }
}
