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

namespace SolidInvoice\UserBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use SolidInvoice\CoreBundle\Test\Factory\CompanyFactory;
use SolidInvoice\CoreBundle\Test\Traits\DoctrineTestTrait;
use SolidInvoice\InstallBundle\Test\EnsureApplicationInstalled;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Enum\UserSettingType;
use SolidInvoice\UserBundle\Onboarding\Manager\OnboardingManager;
use SolidInvoice\UserBundle\Repository\UserRepository;
use SolidInvoice\UserBundle\Repository\UserSettingRepository;
use SolidInvoice\UserBundle\Test\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;

#[Group('functional')]
final class OnboardingFlowTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;
    use Factories;
    use EnsureApplicationInstalled;

    private UserSettingRepository $userSettingRepository;

    protected function setUp(): void
    {
        $this->userSettingRepository = self::getContainer()->get(UserSettingRepository::class);
    }

    public function testCompleteOnboardingCollectsTheProfile(): void
    {
        $user = $this->createUser('test@example.com', 'password');

        $this->browser()
            ->actingAs($user)
            ->visit('/onboarding')
            ->assertSuccessful()
            ->assertOn('/onboarding')
            ->assertSee('Tell us about your business')
            ->fillField('onboarding[company][companyName]', 'Acme Corporation')
            ->fillField('onboarding[company][fullName]', 'Jane Smith')
            ->fillField('onboarding[company][city]', 'Dubai')
            ->selectFieldOption('onboarding[company][country]', 'AE')
            ->fillField('onboarding[company][contactNumber]', '+971 50 123 4567')
            ->selectFieldOption('onboarding[company][companyCurrency]', 'AED')
            ->click('Continue')
            // Straight to the finish - the client and invoice steps are gone.
            ->assertSuccessful()
            ->assertSee('You are in')
            ->assertSee('Get your Trusted badge')
            ->interceptRedirects()
            ->click('I will do this later - take me in')
            ->assertRedirectedTo('/dashboard')
        ;

        $user = self::getContainer()->get(UserRepository::class)->find($user->getId());

        $setting = $this->userSettingRepository->findOneBy([
            'user' => $user,
            'key' => UserSettingType::OnboardComplete,
        ]);
        self::assertNotNull($setting);
        self::assertSame('true', $setting->getValue());

        self::assertCount(1, $user->getCompanies());
        $company = $user->getCompanies()->first();
        self::assertSame('Dubai', $company->getCity());
        self::assertSame('AE', $company->getCountry());
        self::assertSame('+971 50 123 4567', $company->getContactNumber());

        // Nothing is invoiced on somebody's behalf during sign-up any more.
        self::assertCount(0, $this->em->getRepository(Invoice::class)->findBy(['company' => $company]));
    }

    public function testTheProfileStepCannotBeSkipped(): void
    {
        $user = $this->createUser('test2@example.com', 'password');

        $this->browser()
            ->actingAs($user)
            ->visit('/onboarding')
            ->assertSuccessful()
            ->assertSee('Tell us about your business')
            // There is nothing optional left in the flow, so there is no skip.
            ->assertNotSee("I'll do this later")
            ->assertElementNotAttached('#onboarding_navigator_skip')
        ;
    }

    public function testTheFinishPageOffersVerification(): void
    {
        $user = $this->createUser('test3@example.com', 'password');

        $this->browser()
            ->actingAs($user)
            ->visit('/onboarding')
            ->fillField('onboarding[company][companyName]', 'Test Company')
            ->fillField('onboarding[company][fullName]', 'John Doe')
            ->fillField('onboarding[company][city]', 'Sharjah')
            ->selectFieldOption('onboarding[company][country]', 'AE')
            ->fillField('onboarding[company][contactNumber]', '+971 55 987 6543')
            ->click('Continue')
            ->assertSuccessful()
            ->click('Get verified now')
            ->assertSuccessful()
            ->assertOn('/verification')
            ->assertSee('The Trusted badge')
        ;
    }

    public function testInvitedUserDoesNotSeeOnboarding(): void
    {
        // Create a company first
        $company = CompanyFactory::createOne();

        // Create a user with an existing company (invited user)
        $user = UserFactory::createOne([
            'email' => 'invited@example.com',
            'companies' => [$company],
        ])->_real();

        // Hash the password
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($passwordHasher->hashPassword($user, 'password'));
        $this->em->flush();

        $this->browser()
            ->visit('/login')
            ->assertSuccessful()
            ->fillField('_username', 'invited@example.com')
            ->fillField('_password', 'password')
            ->click('Sign in')
            ->followRedirect()
            // Should go to dashboard, not onboarding
            ->assertOn('/dashboard')
        ;
    }

    public function testNewUserRedirectedToOnboardingAfterLogin(): void
    {
        $this->createUser('newuser@example.com', 'password');

        $this->browser()
            ->visit('/login')
            ->assertSuccessful()
            ->fillField('_username', 'newuser@example.com')
            ->fillField('_password', 'password')
            ->click('Sign in')
            ->followRedirect()
            // Should be redirected to onboarding
            ->assertOn('/onboarding')
            ->assertSee('Tell us about your business')
        ;
    }

    public function testOnboardingRedirectsToDashboardIfAlreadyComplete(): void
    {
        $user = $this->createUser('complete@example.com', 'password');

        // Mark onboarding as complete
        $manager = self::getContainer()->get(OnboardingManager::class);
        $manager->dismissOnboarding($user);

        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->visit('/onboarding')
            ->assertRedirectedTo('/create-company')
        ;
    }

    public function testAContactNumberWithoutACountryCodeIsRefused(): void
    {
        $user = $this->createUser('navigator@example.com', 'password');

        $this->browser()
            ->actingAs($user)
            ->visit('/onboarding')
            ->assertSuccessful()
            ->fillField('onboarding[company][companyName]', 'Test Company')
            ->fillField('onboarding[company][fullName]', 'Test Person')
            ->fillField('onboarding[company][city]', 'Dubai')
            ->selectFieldOption('onboarding[company][country]', 'AE')
            // A local number - the exact thing the old profile page collected and
            // that nobody could then dial from outside the country.
            ->fillField('onboarding[company][contactNumber]', '0501234567')
            ->click('Continue')
            ->assertSuccessful()
            // Still on the same page, being told why.
            ->assertSee('Tell us about your business')
            ->assertSee('country code')
        ;

        self::assertCount(0, $user->getCompanies());
    }

    private function createUser(string $email, string $password): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setEnabled(true);
        $user->setVerified(true);

        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
