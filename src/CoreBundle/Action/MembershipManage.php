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

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\WhatsApp\WhatsAppSender;
use SolidInvoice\CoreBundle\Membership\MembershipManager;
use SolidInvoice\CoreBundle\Membership\MembershipPlan;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Repository\Traits\WithoutCompanyFilter;
use SolidInvoice\CoreBundle\Verification\VerificationAlerts;
use SolidInvoice\CoreBundle\Verification\VerificationStore;
use SolidInvoice\NotificationBundle\Entity\TransportSetting;
use SolidInvoice\NotificationBundle\Entity\UserNotification;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Entity\UserInvitation;
use SolidInvoice\UserBundle\Entity\UserSetting;
use SolidInvoice\UserBundle\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Uid\Ulid;
use function array_filter;
use function array_map;
use function array_values;
use function implode;
use function in_array;
use function sprintf;
use function strtolower;
use function strtoupper;
use function trim;

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
    use WithoutCompanyFilter;

    /**
     * Typed next to the button before any ticked account is removed. Compared
     * upper-cased, so it does not matter how it is typed.
     */
    private const CONFIRM_WORD = 'REMOVE';

    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly MembershipManager $membership,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly VerificationAlerts $alerts,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return match ((string) $request->request->get('intent', 'save')) {
                'reset_password' => $this->handleResetPassword($request),
                'delete_company' => $this->handleDeleteCompany($request),
                'delete_account' => $this->handleDeleteAccount($request),
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

        // Accounts belonging to no business. They are drawn in their own section
        // because every other list on this page hangs off a company, so these
        // were invisible - which is how one could be sitting there holding an
        // e-mail address that the sign-up form kept reporting as taken.
        $orphans = $this->withoutCompanyFilter(fn (): array => array_values(array_filter(
            $this->userRepository->findWithoutCompany(),
            static fn (User $user): bool => ! in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true),
        )));

        return $this->render('@SolidInvoiceCore/Membership/manage.html.twig', [
            'rows' => $rows,
            'orphans' => $orphans,
            'plans' => MembershipPlan::cases(),
        ]);
    }

    /**
     * The console reads every business's accounts, so its queries run with the
     * company filter borrowed - see the trait for why it is suspended and not
     * disabled.
     */
    private function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
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
                // What the ACCOUNT proved about this same number, as opposed to
                // what the owner has ticked by hand below it.
                'contactOpenedOnWhatsAppAt' => $this->contactOpenedOnWhatsAppAt($company, $users),
                'otherConfirmedNumbers' => $this->otherConfirmedNumbers($company, $users),
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
     * When somebody on this account opened the WhatsApp confirmation link on the
     * SAME number the business gives as its contact number.
     *
     * Compared through chatId() rather than as strings: +971 50 123 4567,
     * 00971501234567 and 971501234567 are one phone, and a business that typed
     * its number one way on the profile and another on the account would
     * otherwise read as two different numbers and be marked unconfirmed.
     *
     * @param list<User> $users
     */
    private function contactOpenedOnWhatsAppAt(Company $company, array $users): ?DateTimeImmutable
    {
        $contact = WhatsAppSender::chatId((string) $company->getContactNumber());

        if ($contact === null) {
            return null;
        }

        $earliest = null;

        foreach ($users as $user) {
            $confirmedAt = $user->getMobileVerifiedAt();

            if (! $confirmedAt instanceof DateTimeImmutable) {
                continue;
            }

            if (WhatsAppSender::chatId((string) $user->getMobile()) !== $contact) {
                continue;
            }

            // The first time it was confirmed, not the last account to do it.
            if ($earliest === null || $confirmedAt < $earliest) {
                $earliest = $confirmedAt;
            }
        }

        return $earliest;
    }

    /**
     * Numbers this account has confirmed that are NOT the business's contact
     * number. Worth showing on its own: it means there is a second working
     * number for this business that the profile does not mention.
     *
     * @param list<User> $users
     * @return list<array{number: string, at: DateTimeImmutable}>
     */
    private function otherConfirmedNumbers(Company $company, array $users): array
    {
        $contact = WhatsAppSender::chatId((string) $company->getContactNumber());
        $others = [];

        foreach ($users as $user) {
            $confirmedAt = $user->getMobileVerifiedAt();
            $number = trim((string) $user->getMobile());

            if (! $confirmedAt instanceof DateTimeImmutable || $number === '') {
                continue;
            }

            if (WhatsAppSender::chatId($number) === $contact) {
                continue;
            }

            $others[] = ['number' => $number, 'at' => $confirmedAt];
        }

        return $others;
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

        // Read before the write: the member is told only when the badge is newly
        // granted. This form is saved for a dozen other reasons (plan, term,
        // marketplace, classifieds) and none of them should send an email
        // congratulating a business that was verified last month.
        $wasVerified = $company->isVerified();

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

        // Granted just now, not already held. Taking a badge away sends nothing:
        // that is a conversation the owner will want to have in his own words.
        if ($verified && ! $wasVerified) {
            $this->alerts->badgeGranted($company);
        }

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

        // Deleting a business used to be one click behind a browser confirm()
        // box. A confirm() is not a safeguard: it is one keystroke away, and a
        // browser stops showing it entirely once somebody ticks "prevent this
        // page from creating more dialogues" - at which point the same click
        // deletes with no warning at all. The name has to be typed instead, and
        // it is checked HERE and not in the page, so nothing done to the form in
        // the browser can skip it.
        if (trim((string) $request->request->get('confirm_name')) !== trim($company->getName())) {
            $this->addFlash('error', sprintf('Nothing was deleted. To delete %s, type its name into the box exactly as shown.', $company->getName()));

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

        // All of it, or none of it.
        //
        // deleteCompany() flushes and COMMITS on its own, and the orphaned
        // accounts were then removed in a second, separate commit. When that
        // second half failed - which it always did, on the user_settings foreign
        // key - the first half stayed done: the business was gone and the account
        // that existed only for it survived, still holding its e-mail address and
        // WhatsApp number so neither could be used to sign up again. The client
        // hit exactly that, and the leftovers are why "I deleted this account but
        // it still says it exists".
        //
        // One transaction means a failure now leaves the console exactly as it
        // was, and there is nothing to clean up by hand afterwards.
        $this->entityManager->wrapInTransaction(function () use ($company, $soleOwners): void {
            // Removing the company clears its join-table rows and cascades its data.
            $this->companyRepository->deleteCompany($company->getId());

            foreach ($soleOwners as $user) {
                $this->purgeRowsBlockingDelete($user);

                $this->entityManager->remove($user);
            }

            $this->entityManager->flush();
        });

        $this->addFlash('success', sprintf('%s and its account(s) were deleted.', $name));

        return $this->redirectToRoute('_membership_manage');
    }

    /**
     * Clear the rows that would otherwise stop an account being deleted.
     *
     * Four tables point at users through a foreign key that was created without
     * ON DELETE - which in MySQL means RESTRICT - and no entity owns an inverse
     * collection of any of them, so Doctrine does not know they are there and
     * the database refuses the delete instead of following it.
     *
     * Version30000_48 and Version30000_49 correct the constraints. This clears
     * the rows anyway, so account deletion also works on an install where those
     * migrations have not run yet - and it costs four cheap deletes.
     */
    private function purgeRowsBlockingDelete(User $user): void
    {
        foreach ([UserSetting::class, UserInvitation::class, TransportSetting::class, UserNotification::class] as $entity) {
            $property = $entity === UserInvitation::class ? 'invitedBy' : 'user';

            $this->entityManager->createQuery(
                sprintf('DELETE FROM %s e WHERE e.%s = :user', $entity, $property)
            )->setParameter('user', $user)->execute();
        }
    }

    /**
     * Remove an account outright.
     *
     * Needed because deleting a business only removes the accounts that existed
     * solely for it, and because the half-finished deletes described above left
     * accounts behind that belong to no business and appear in no per-company
     * list. Without this the only way to free up an e-mail address or a WhatsApp
     * number was to edit the database by hand.
     */
    private function handleDeleteAccount(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('membership.manage', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_membership_manage');
        }

        /** @var list<string> $selected */
        $selected = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) $request->request->all('emails'),
        ), static fn (string $value): bool => $value !== ''));

        if ($selected === []) {
            $this->addFlash('error', 'Nothing was ticked, so nothing was removed.');

            return $this->redirectToRoute('_membership_manage');
        }

        // Checked here rather than in the page, for the same reason the company
        // delete is: anything the browser is asked to enforce can be skipped.
        //
        // A word rather than each address re-typed: the list is now ticked by
        // hand, which is already deliberate, and several of these usually go at
        // once. Retyping four addresses to clear four dead test signups is the
        // kind of friction that gets a safeguard worked around instead of used.
        if (strtoupper(trim((string) $request->request->get('confirm'))) !== self::CONFIRM_WORD) {
            $this->addFlash('error', sprintf(
                'Nothing was removed. Tick the accounts, then type %s into the box next to the button.',
                self::CONFIRM_WORD
            ));

            return $this->redirectToRoute('_membership_manage');
        }

        $signedInEmail = ($signedIn = $this->getUser()) instanceof User
            ? strtolower((string) $signedIn->getEmail())
            : null;

        $removed = [];
        $stranded = [];
        $refused = [];

        foreach ($selected as $email) {
            $user = $this->withoutCompanyFilter(
                fn (): ?User => $this->userRepository->findOneBy(['email' => $email]),
            );

            if (! $user instanceof User) {
                $refused[] = sprintf('%s could not be found', $email);

                continue;
            }

            // Re-checked per account, not once for the batch: the ticked list is
            // whatever was posted, so every single entry has to clear the same
            // two rules on its own.
            if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                $refused[] = sprintf('%s is the platform owner', $email);

                continue;
            }

            if ($signedInEmail !== null && strtolower((string) $user->getEmail()) === $signedInEmail) {
                $refused[] = sprintf('%s is the account you are signed in with', $email);

                continue;
            }

            // Businesses this account is the last member of. Removing it would
            // leave them with nobody who can sign in, so say so rather than
            // quietly stranding them - and never delete a business from here,
            // which is a separate, deliberate action with its own confirmation.
            foreach ($this->withoutCompanyFilter(static function () use ($user): array {
                $names = [];

                foreach ($user->getCompanies() as $company) {
                    if ($company instanceof Company && $company->getUsers()->count() <= 1) {
                        $names[] = $company->getName();
                    }
                }

                return $names;
            }) as $name) {
                $stranded[$name] = $name;
            }

            $this->entityManager->wrapInTransaction(function () use ($user): void {
                $this->purgeRowsBlockingDelete($user);

                $this->entityManager->remove($user);
                $this->entityManager->flush();
            });

            $removed[] = $email;
        }

        if ($removed !== []) {
            $this->addFlash('success', sprintf(
                '%s removed. Those e-mail addresses and WhatsApp numbers can be used to sign up again.',
                implode(', ', $removed)
            ));
        }

        foreach ($refused as $reason) {
            $this->addFlash('error', sprintf('Not removed - %s.', $reason));
        }

        if ($stranded !== []) {
            $this->addFlash('error', sprintf(
                'Note: %s now has no account that can sign in. Delete it above if it is not needed.',
                implode(', ', $stranded)
            ));
        }

        return $this->redirectToRoute('_membership_manage');
    }

    private function resolveCompany(Request $request): ?Company
    {
        $companyId = (string) $request->request->get('company');

        return Ulid::isValid($companyId) ? $this->companyRepository->find(Ulid::fromString($companyId)) : null;
    }
}
