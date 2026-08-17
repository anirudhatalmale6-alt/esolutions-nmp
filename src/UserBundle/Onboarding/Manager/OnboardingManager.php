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

namespace SolidInvoice\UserBundle\Onboarding\Manager;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Membership\MembershipPlan;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Enum\UserSettingType;
use SolidInvoice\UserBundle\Onboarding\DTO\OnboardingData;
use SolidInvoice\UserBundle\Repository\UserSettingRepository;
use function json_decode;
use function json_encode;
use function mb_strrpos;
use function mb_substr;
use function preg_replace;
use function trim;

/**
 * @see \SolidInvoice\UserBundle\Tests\Onboarding\Manager\OnboardingManagerTest
 */
final readonly class OnboardingManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompanyRepository $companyRepository,
        private UserSettingRepository $userSettingRepository,
    ) {
    }

    /**
     * Check if user has completed onboarding
     */
    public function isOnboardingComplete(User $user): bool
    {
        $setting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardComplete,
        ]);

        return in_array($setting?->getValue(), ['true', 'dismissed'], true);
    }

    /**
     * Get current onboarding step
     */
    public function getCurrentStep(User $user): ?string
    {
        $setting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardingStep,
        ]);

        return $setting?->getValue();
    }

    /**
     * Update onboarding step
     */
    public function setCurrentStep(User $user, string $step): void
    {
        $this->userSettingRepository->saveSetting($user, UserSettingType::OnboardingStep, $step);
        $this->entityManager->flush();
    }

    /**
     * Mark step as skipped
     * @throws JsonException
     */
    public function markStepSkipped(User $user, string $step): void
    {
        $setting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardingSkipped,
        ]);

        $skipped = $setting ? json_decode((string) $setting->getValue(), true, flags: JSON_THROW_ON_ERROR) : [];
        $skipped[] = $step;

        $this->userSettingRepository->saveSetting($user, UserSettingType::OnboardingSkipped, json_encode($skipped, JSON_THROW_ON_ERROR));
        $this->entityManager->flush();
    }

    /**
     * Complete onboarding process
     */
    public function completeOnboarding(User $user, OnboardingData $data, ?string $referralCode = null, ?string $referralName = null): void
    {
        // 1. Create company
        $company = $this->createCompany($data);

        // A business that signed up through a sales / referral link is put on the
        // Basic plan for one year right away (invoicing + internal tools) and
        // stamped with the rep who brought it in, so it can start using the portal
        // immediately - the rep's referral IS the vetting. It stays Not Verified:
        // "verified" is a separate trusted badge the platform owner grants by hand,
        // and the prerequisite for a Premium upgrade, but it is NOT required for
        // Basic access. The owner reviews the business at leisure and can then mark
        // it trusted / move it to Premium.
        if ($referralCode !== null && $referralCode !== '') {
            $company
                ->setReferredByCode($referralCode)
                ->setReferredByName($referralName)
                ->setMembershipPlan(MembershipPlan::Basic->value)
                ->setMembershipExpiresAt(new \DateTimeImmutable('+1 year'))
                ->setVerified(false);
        }

        $user->addCompany($company);

        // 2. The person, not the business. Their name was being asked for on a
        // profile page most people never opened, which is why every account in
        // the panel showed an email address and nothing else.
        $this->applyFullName($user, $data->fullName);

        if ($data->contactNumber !== null && $data->contactNumber !== '') {
            $user->setMobile($data->contactNumber);
        }

        $this->entityManager->persist($user);

        // 3. Mark onboarding complete
        $this->userSettingRepository->saveSetting($user, UserSettingType::OnboardComplete, 'true');
        $this->userSettingRepository->saveSetting(
            $user,
            UserSettingType::OnboardingCompletedAt,
            CarbonImmutable::now()->format('Y-m-d H:i:s')
        );

        $this->entityManager->flush();
    }

    /**
     * Start onboarding (called after registration)
     */
    public function startOnboarding(User $user): void
    {
        $this->userSettingRepository->saveSetting(
            $user,
            UserSettingType::OnboardingStartedAt,
            CarbonImmutable::now()->format('Y-m-d H:i:s')
        );
        $this->setCurrentStep($user, 'company');
    }

    /**
     * Dismiss onboarding (user chooses not to complete)
     */
    public function dismissOnboarding(User $user): void
    {
        $this->userSettingRepository->saveSetting($user, UserSettingType::OnboardComplete, 'dismissed');
        $this->userSettingRepository->saveSetting(
            $user,
            UserSettingType::OnboardingCompletedAt,
            CarbonImmutable::now()->format('Y-m-d H:i:s')
        );
        $this->entityManager->flush();
    }

    /**
     * Helper: Create company from data
     */
    private function createCompany(OnboardingData $data): Company
    {
        $company = new Company();
        $company->setName((string) $data->companyName);
        $company->currency = $data->companyCurrency ?? 'AED';
        $company
            ->setCity($data->city)
            ->setCountry($data->country)
            ->setContactNumber($data->contactNumber);

        $this->companyRepository->save($company);

        return $company;
    }

    /**
     * One box on the form, two columns in the database.
     *
     * People write their name the way they say it, so the split is the last
     * space: everything before it is the first name, the remainder is the
     * surname. A single word (some members go by one name) becomes the first
     * name with no surname rather than being rejected - the form already made
     * sure something was typed, and arguing about the shape of a stranger's name
     * is not sign-up's job.
     */
    private function applyFullName(User $user, ?string $fullName): void
    {
        $fullName = trim((string) preg_replace('/\s+/u', ' ', (string) $fullName));

        if ($fullName === '') {
            return;
        }

        $split = mb_strrpos($fullName, ' ');

        if ($split === false) {
            $user->setFirstName($fullName);

            return;
        }

        $user->setFirstName(mb_substr($fullName, 0, $split));
        $user->setLastName(mb_substr($fullName, $split + 1));
    }
}
