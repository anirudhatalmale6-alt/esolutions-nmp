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

namespace SolidInvoice\UserBundle\Tests\Onboarding\Manager;

use PHPUnit\Framework\Attributes\CoversClass;
use SolidInvoice\ClientBundle\Repository\ClientRepository;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Test\Traits\DoctrineTestTrait;
use SolidInvoice\InstallBundle\Test\EnsureApplicationInstalled;
use SolidInvoice\InvoiceBundle\Repository\InvoiceRepository;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Enum\UserSettingType;
use SolidInvoice\UserBundle\Onboarding\DTO\OnboardingData;
use SolidInvoice\UserBundle\Onboarding\Manager\OnboardingManager;
use SolidInvoice\UserBundle\Repository\UserSettingRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(OnboardingManager::class)]
final class OnboardingManagerTest extends KernelTestCase
{
    use DoctrineTestTrait;
    use EnsureApplicationInstalled;

    private OnboardingManager $manager;

    private UserSettingRepository $userSettingRepository;

    private ClientRepository $clientRepository;

    private InvoiceRepository $invoiceRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userSettingRepository = self::getContainer()->get(UserSettingRepository::class);
        $companyRepository = self::getContainer()->get(CompanyRepository::class);
        $this->clientRepository = self::getContainer()->get(ClientRepository::class);
        $this->invoiceRepository = self::getContainer()->get(InvoiceRepository::class);

        // Manually create OnboardingManager since it may not be public in test container
        $this->manager = new OnboardingManager(
            $this->em,
            $companyRepository,
            $this->clientRepository,
            $this->invoiceRepository,
            $this->userSettingRepository
        );
    }

    public function testIsOnboardingCompleteReturnsFalseWhenNotComplete(): void
    {
        $user = $this->createUser('test@example.com');
        $this->em->persist($user);
        $this->em->flush();

        self::assertFalse($this->manager->isOnboardingComplete($user));
    }

    public function testIsOnboardingCompleteReturnsTrueWhenComplete(): void
    {
        $user = $this->createUser('test2@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->manager->dismissOnboarding($user);

        self::assertTrue($this->manager->isOnboardingComplete($user));
    }

    public function testGetCurrentStepReturnsNullWhenNotSet(): void
    {
        $user = $this->createUser('test3@example.com');
        $this->em->persist($user);
        $this->em->flush();

        self::assertNull($this->manager->getCurrentStep($user));
    }

    public function testGetCurrentStepReturnsStepName(): void
    {
        $user = $this->createUser('test4@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->manager->setCurrentStep($user, 'client');

        self::assertSame('client', $this->manager->getCurrentStep($user));
    }

    public function testSetCurrentStepSavesSetting(): void
    {
        $user = $this->createUser('test5@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->manager->setCurrentStep($user, 'invoice');

        $setting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardingStep,
        ]);

        self::assertNotNull($setting);
        self::assertSame('invoice', $setting->getValue());
    }

    public function testMarkStepSkippedAddsStepToSkippedList(): void
    {
        $user = $this->createUser('test6@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->manager->markStepSkipped($user, 'client');

        $setting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardingSkipped,
        ]);

        self::assertNotNull($setting);
        $skipped = json_decode((string) $setting->getValue(), true);
        self::assertSame(['client'], $skipped);
    }

    public function testStartOnboardingSetsInitialStep(): void
    {
        $user = $this->createUser('test7@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->manager->startOnboarding($user);

        self::assertSame('company', $this->manager->getCurrentStep($user));

        $startedAtSetting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardingStartedAt,
        ]);
        self::assertNotNull($startedAtSetting);
    }

    public function testCompleteOnboardingStoresTheProfile(): void
    {
        $user = $this->createUser('test8@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $data = new OnboardingData();
        $data->companyName = 'Test Company';
        $data->fullName = 'Jane Smith';
        $data->city = 'Dubai';
        $data->country = 'AE';
        $data->contactNumber = '+971 50 123 4567';
        $data->companyCurrency = 'AED';

        $this->manager->completeOnboarding($user, $data);

        self::assertTrue($this->manager->isOnboardingComplete($user));

        self::assertCount(1, $user->getCompanies());
        $company = $user->getCompanies()->first();
        self::assertSame('Test Company', $company->getName());
        self::assertSame('AED', $company->currency);
        self::assertSame('Dubai', $company->getCity());
        self::assertSame('AE', $company->getCountry());
        self::assertSame('+971 50 123 4567', $company->getContactNumber());

        // The one name box becomes the two columns the rest of the app reads.
        self::assertSame('Jane', $user->getFirstName());
        self::assertSame('Smith', $user->getLastName());
        self::assertSame('+971 50 123 4567', $user->getMobile());
    }

    public function testCompleteOnboardingCreatesNoClientOrInvoice(): void
    {
        $user = $this->createUser('test9@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $data = new OnboardingData();
        $data->companyName = 'Test Company';
        $data->fullName = 'John Doe';
        $data->city = 'Sharjah';
        $data->country = 'AE';
        $data->contactNumber = '+971 55 987 6543';
        $data->companyCurrency = 'EUR';

        $this->manager->completeOnboarding($user, $data);

        self::assertTrue($this->manager->isOnboardingComplete($user));

        $company = $user->getCompanies()->first();
        self::assertSame('EUR', $company->currency);

        // Signing up no longer invents a customer or an invoice on the member's
        // behalf - both used to be steps in the flow and both are gone.
        self::assertCount(0, $this->clientRepository->findBy(['company' => $company]));
        self::assertCount(0, $this->invoiceRepository->findBy(['company' => $company]));
    }

    public function testCompleteOnboardingLeavesTheCompanyUnverified(): void
    {
        $user = $this->createUser('test10@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $data = new OnboardingData();
        $data->companyName = 'Test Company';
        $data->fullName = 'Jane Doe';
        $data->city = 'Dubai';
        $data->country = 'AE';
        $data->contactNumber = '+971 50 000 0000';
        $data->companyCurrency = 'GBP';

        $this->manager->completeOnboarding($user, $data);

        $company = $user->getCompanies()->first();

        // The trusted badge is granted by hand, after somebody has looked at the
        // documents. Joining never grants it, and never confirms the number.
        self::assertFalse($company->isVerified());
        self::assertFalse($company->isContactVerified());
        self::assertFalse($company->hasVerificationDocuments());
        self::assertNull($company->getVerificationSubmittedAt());
    }

    public function testDismissOnboardingMarksAsComplete(): void
    {
        $user = $this->createUser('test11@example.com');
        $this->em->persist($user);
        $this->em->flush();

        $this->manager->dismissOnboarding($user);

        $setting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardComplete,
        ]);

        self::assertNotNull($setting);
        self::assertSame('dismissed', $setting->getValue());
        self::assertTrue($this->manager->isOnboardingComplete($user));
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('dummy-password');
        return $user;
    }
}
