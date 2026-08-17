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

namespace SolidInvoice\CoreBundle\Action;

use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Membership\MembershipManager;
use SolidInvoice\CoreBundle\Membership\MembershipPlan;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Verification\VerificationStore;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Uid\Ulid;
use function array_filter;
use function implode;
use function in_array;

/**
 * Super-user membership console. The platform owner sees every vendor company
 * with its plan, expiry, verified badge and comp status, and can:
 *   - verify / un-verify a company
 *   - enable the Marketplace for it by name, without putting it on Premium
 *   - set its plan (None / Basic / Premium) for an annual term or lifetime
 *   - grant it complimentary (free) - no Stripe charge
 *   - reset the password of any account (no e-mail server needed)
 *   - delete a company outright (removes its data and any account left with no
 *     other company) - used to clean up duplicate/test sign-ups
 *
 * Premium cannot be granted to an unverified company (matches the rule that
 * verification is required for Premium). Only the Super User (platform owner)
 * may open this page.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class MembershipManage extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly MembershipManager $membership,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return match ((string) $request->request->get('intent', 'save')) {
                'reset_password' => $this->handleResetPassword($request),
                'delete_company' => $this->handleDeleteCompany($request),
                default => $this->handleSave($request),
            };
        }

        $companies = $this->companyRepository->findBy([], ['name' => 'ASC']);

        // The "company" Doctrine filter scopes User queries to the CURRENT company
        // (the owner's own company), so lazily loading another company's users comes
        // back empty - which is why other businesses showed "No account linked" and
        // no e-mail. This console is super-admin only and legitimately needs to see
        // every company's accounts, so build the rows with the filter switched off.
        $rows = $this->withoutCompanyFilter(fn (): array => $this->buildRows($companies));

        return $this->render('@SolidInvoiceCore/Membership/manage.html.twig', [
            'rows' => $rows,
            'plans' => MembershipPlan::cases(),
        ]);
    }

    /**
     * Run $callback with the "company" Doctrine filter disabled, then restore it.
     * The super-admin console must reach every company's accounts, which the
     * current-company filter would otherwise hide.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withoutCompanyFilter(callable $callback): mixed
    {
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('company');

        if ($wasEnabled) {
            $filters->disable('company');
        }

        try {
            return $callback();
        } finally {
            if ($wasEnabled) {
                $filters->enable('company');
            }
        }
    }

    /**
     * @param list<Company> $companies
     * @return list<array<string, mixed>>
     */
    private function buildRows(array $companies): array
    {
        $rows = [];
        foreach ($companies as $company) {
            $users = [];
            $hasSuperAdmin = false;

            foreach ($company->getUsers() as $user) {
                if (! $user instanceof User) {
                    continue;
                }

                if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                    $hasSuperAdmin = true;
                }

                $users[] = $user;
            }

            $rows[] = [
                'company' => $company,
                'plan' => $this->membership->planFor($company),
                'active' => $this->membership->isActive($company),
                'expiresAt' => $company->getMembershipExpiresAt(),
                'complimentary' => $company->isMembershipComplimentary(),
                'verified' => $company->isVerified(),
                // The grant itself, as ticked on this page...
                'marketplaceGranted' => $company->hasMarketplaceAccess(),
                'liveStock' => $company->hasLiveStock(),
                'classifiedsAccess' => $company->hasClassifiedsAccess(),
                // ...and whether the business can actually reach the Marketplace
                // today, which Premium also satisfies. Shown so a Premium company
                // does not read as shut out just because the box is unticked.
                'marketplaceAccess' => $this->membership->hasMarketplaceAccess($company),
                'users' => $users,
                'contactVerified' => $company->isContactVerified(),
                'location' => $this->location($company),
                // Only the documents that were actually sent in, so the panel shows
                // three buttons or none rather than three greyed-out ones.
                'documents' => $this->documents($company),
                // A company the platform owner belongs to (any super-admin member)
                // can't be deleted from here, so the owner can't wipe themselves out.
                'canDelete' => ! $hasSuperAdmin,
            ];
        }

        return $rows;
    }

    /**
     * "Dubai, United Arab Emirates" from the two columns, skipping whichever is
     * missing. An unknown country code is shown as it was stored rather than
     * dropped - if something odd is in that column the owner should see it.
     */
    private function location(Company $company): string
    {
        $city = (string) $company->getCity();
        $code = (string) $company->getCountry();

        $country = $code !== '' && Countries::exists($code) ? Countries::getName($code, 'en') : $code;

        return implode(', ', array_filter([$city, $country], static fn (string $part): bool => $part !== ''));
    }

    /**
     * @return list<array{kind: string, label: string}>
     */
    private function documents(Company $company): array
    {
        $documents = [];

        foreach ([
            [VerificationStore::ID_FRONT, 'National ID - front', $company->getIdFrontPath()],
            [VerificationStore::ID_BACK, 'National ID - back', $company->getIdBackPath()],
            [VerificationStore::PASSPORT, 'Passport', $company->getPassportPath()],
        ] as [$kind, $label, $path]) {
            if ($path !== null) {
                $documents[] = ['kind' => $kind, 'label' => $label];
            }
        }

        return $documents;
    }

    private function handleSave(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('membership.manage', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_membership_manage');
        }

        $company = $this->resolveCompany($request);

        if (! $company instanceof Company) {
            $this->addFlash('error', 'That company could not be found.');

            return $this->redirectToRoute('_membership_manage');
        }

        $plan = MembershipPlan::fromValue((string) $request->request->get('plan'));
        $verified = $request->request->getBoolean('verified');
        $complimentary = $request->request->getBoolean('complimentary');
        $marketplaceAccess = $request->request->getBoolean('marketplace_access');
        $liveStock = $request->request->getBoolean('live_stock');
        $term = (string) $request->request->get('term', 'annual');

        // Verification is a prerequisite for Premium.
        if ($plan === MembershipPlan::Premium && ! $verified) {
            $this->addFlash('error', sprintf('%s must be verified before it can be put on Premium. Tick "Verified" and save again.', $company->getName()));

            return $this->redirectToRoute('_membership_manage');
        }

        // Persist verification first so it is stored even for a None/Basic plan.
        $this->membership->setVerified($company, $verified);

        // Whether anyone has actually reached them on that number. Its own tick,
        // not part of the badge - one is a fact, the other is a judgement.
        $company->setContactVerified($request->request->getBoolean('contact_verified'));
        $this->entityManager->flush();

        // The Marketplace grant is deliberately independent of the plan - that is
        // the whole point of it - so it is stored whatever the plan below says.
        $this->membership->setMarketplaceAccess($company, $marketplaceAccess);

        // Whether this business's own documents move its stock. Off means it
        // keeps working exactly as it did, with quantities coming from Tally, so
        // switching it on is always a decision somebody made rather than
        // something that happened to them.
        $this->membership->setLiveStock($company, $liveStock);

        // Whether they may run a paid classified advert. Independent of the plan
        // for the same reason the Marketplace grant is: the four places on that
        // page are sold one at a time, by name.
        $this->membership->setClassifiedsAccess($company, $request->request->getBoolean('classifieds_access'));

        // Work out the expiry: no plan or a "lifetime" term means no expiry;
        // otherwise one year from today.
        $expiresAt = null;
        if ($plan->isPaid() && $term === 'annual') {
            $expiresAt = new \DateTimeImmutable('+1 year');
        }

        $this->membership->grant($company, $plan, $expiresAt, $plan->isPaid() && $complimentary);

        $this->addFlash('success', sprintf(
            '%s updated: %s%s. Marketplace %s.',
            $company->getName(),
            $plan->label(),
            $complimentary && $plan->isPaid() ? ' (complimentary)' : '',
            $this->membership->hasMarketplaceAccess($company) ? 'enabled' : 'not enabled'
        ));

        return $this->redirectToRoute('_membership_manage');
    }

    private function handleResetPassword(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('membership.manage', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_membership_manage');
        }

        $email = trim((string) $request->request->get('email'));
        $password = (string) $request->request->get('password');

        // Look the account up with the company filter off - it may belong to any
        // company on the portal, not the owner's current one.
        $user = $email === '' ? null : $this->withoutCompanyFilter(
            fn (): ?User => $this->userRepository->findOneBy(['email' => $email]),
        );

        if (! $user instanceof User) {
            $this->addFlash('error', 'That account could not be found.');

            return $this->redirectToRoute('_membership_manage');
        }

        if (strlen($password) < 6) {
            $this->addFlash('error', 'Please enter a password of at least 6 characters.');

            return $this->redirectToRoute('_membership_manage');
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->eraseCredentials();
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Password updated for %s.', $email));

        return $this->redirectToRoute('_membership_manage');
    }

    private function handleDeleteCompany(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('membership.manage', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_membership_manage');
        }

        $company = $this->resolveCompany($request);

        if (! $company instanceof Company) {
            $this->addFlash('error', 'That company could not be found.');

            return $this->redirectToRoute('_membership_manage');
        }

        // Capture the members and whether each belongs ONLY to this company, before
        // deleting. Read them with the company filter off, otherwise another
        // company's members are invisible and its account would be left orphaned.
        $members = $this->withoutCompanyFilter(static function () use ($company): array {
            $out = [];

            foreach ($company->getUsers() as $user) {
                if ($user instanceof User) {
                    $out[] = $user;
                }
            }

            return $out;
        });

        // Refuse if the platform owner (a super-admin) is a member, so the console
        // can never delete the owner's own company.
        $soleOwners = [];

        foreach ($members as $user) {
            if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                $this->addFlash('error', sprintf('%s can\'t be deleted here because the platform owner is a member of it.', $company->getName()));

                return $this->redirectToRoute('_membership_manage');
            }

            if ($user->getCompanies()->count() === 1) {
                $soleOwners[] = $user;
            }
        }

        $name = $company->getName();

        // Removing the company clears its join-table rows and cascades its data.
        $this->companyRepository->deleteCompany($company->getId());

        // Any account that had no other company is now orphaned - remove it too so
        // duplicate/test sign-ups don't linger in the accounts list.
        foreach ($soleOwners as $user) {
            $this->entityManager->remove($user);
        }

        $this->entityManager->flush();

        $this->addFlash('success', sprintf('%s and its account(s) were deleted.', $name));

        return $this->redirectToRoute('_membership_manage');
    }

    private function resolveCompany(Request $request): ?Company
    {
        $companyId = (string) $request->request->get('company');

        return Ulid::isValid($companyId) ? $this->companyRepository->find(Ulid::fromString($companyId)) : null;
    }
}
