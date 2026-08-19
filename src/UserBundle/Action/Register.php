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

namespace SolidInvoice\UserBundle\Action;

use SolidInvoice\CoreBundle\Entity\ReferralLink;
use SolidInvoice\CoreBundle\Repository\ReferralLinkRepository;
use SolidInvoice\UserBundle\DTO\Registration;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Entity\UserInvitation;
use SolidInvoice\UserBundle\Enum\PortalRole;
use SolidInvoice\UserBundle\Enum\UserSettingType;
use SolidInvoice\UserBundle\Form\Type\RegisterType;
use SolidInvoice\UserBundle\Repository\UserInvitationRepository;
use SolidInvoice\UserBundle\Repository\UserRepository;
use SolidInvoice\UserBundle\Repository\UserSettingRepository;
use SolidWorx\Toggler\ToggleInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Ulid;
use function assert;
use function is_string;

final class Register extends AbstractController
{
    /** Session keys that carry a referral from the join link through to onboarding. */
    public const string SESSION_REFERRAL_CODE = '_referral_code';

    public const string SESSION_REFERRAL_NAME = '_referral_name';

    public function __construct(
        private readonly UserPasswordHasherInterface $userPasswordHasher,
        private readonly UserInvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly Security $security,
        private readonly ToggleInterface $toggle,
        private readonly ReferralLinkRepository $referralRepository,
        private readonly UserSettingRepository $userSettingRepository,
        #[Autowire('%env(SOLIDINVOICE_TURNSTILE_SITE_KEY)%')]
        private readonly ?string $turnstileSiteKey = null,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $invitation = null;

        if ($request->query->has('invitation')) {
            $invitationId = $request->query->getString('invitation');

            if (! Ulid::isValid($invitationId)) {
                throw $this->createNotFoundException('Invitation is not valid');
            }

            $invitation = $this->invitationRepository->find(Ulid::fromString($invitationId));

            if (! $invitation instanceof UserInvitation) {
                throw $this->createNotFoundException('Invitation is not valid');
            }

            if ($invitation->isExpired()) {
                $this->invitationRepository->save($invitation->markExpired());

                throw $this->createNotFoundException('Invitation is not valid');
            }
        }

        // Open public registration is CLOSED. A brand-new business can only sign up
        // through a valid, active sales / referral link (see ReferralLink); existing
        // companies still add staff through a UserInvitation. Anything else is a 404.
        // This is enforced in code so a flipped env toggle can never reopen the hole.
        $session = $request->getSession();
        $referral = null;

        $refCode = $request->query->get('ref') ?? $session->get(self::SESSION_REFERRAL_CODE);

        if (is_string($refCode) && $refCode !== '') {
            $referral = $this->referralRepository->findActiveByCode($refCode);
        }

        if (! $invitation instanceof UserInvitation && ! $referral instanceof ReferralLink) {
            // Not a 404 - show a friendly, on-brand "invite only" page telling the
            // visitor to contact the B2B Network Team for activation, rather than a
            // bare "not found" error.
            return $this->render(
                '@SolidInvoiceUser/Security/registration_closed.html.twig',
                [],
                new Response('', Response::HTTP_FORBIDDEN),
            );
        }

        if ($invitation instanceof UserInvitation) {
            // Joining an existing company via a staff invite - never a referral signup.
            $session->remove(self::SESSION_REFERRAL_CODE);
            $session->remove(self::SESSION_REFERRAL_NAME);
        } elseif ($referral instanceof ReferralLink) {
            // Remember the rep for the onboarding step, which is where the new company
            // is actually created and gets stamped + put on Basic.
            $session->set(self::SESSION_REFERRAL_CODE, $referral->getCode());
            $session->set(self::SESSION_REFERRAL_NAME, $referral->getRepName());
        }

        $form =
            $invitation instanceof UserInvitation ?
                $this->createForm(RegisterType::class, null, ['email' => $invitation->getEmail()]) :
                $this->createForm(RegisterType::class);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data instanceof Registration);

            $user = new User();
            $user->setEmail($invitation instanceof UserInvitation ? $invitation->getEmail() : $data->email);
            $user->setPassword($data->plainPassword);

            // If invited, add to existing company
            if ($invitation instanceof UserInvitation) {
                $user->addCompany($invitation->getCompany());
                $this->invitationRepository->delete($invitation);
            } else {
                // A self-registering business owns the workspace it is about to
                // create during onboarding, so it needs full admin rights over
                // that company (otherwise the very first redirect after
                // onboarding - to the invoice it just made - is denied, since a
                // plain ROLE_USER can't reach /invoices). The membership funnel
                // still holds the account on the pending-approval page until the
                // platform owner verifies it, so this grants no early access.
                $user->addRole(PortalRole::Admin->value);
            }

            // For regular users, company will be created during onboarding

            $user->setPassword($this->userPasswordHasher->hashPassword($user, $user->getPassword()));
            $user->setEnabled(true);
            $user->eraseCredentials();
            $this->userRepository->save($user);

            // Keep the referral on the ACCOUNT as well as in the session. The
            // session is all that carried it before, and a session does not
            // survive a closed browser - somebody who signed up on their phone in
            // the evening and finished the next morning arrived at onboarding as
            // an unreferred stranger, so their company was created with no rep
            // against it and no Basic plan, and they landed on the pending page
            // instead of inside the portal.
            if (! $invitation instanceof UserInvitation && $referral instanceof ReferralLink) {
                $this->userSettingRepository->saveSetting($user, UserSettingType::ReferralCode, $referral->getCode());
                $this->userSettingRepository->saveSetting($user, UserSettingType::ReferralName, $referral->getRepName());
            }

            // Auto-login and redirect
            // OnboardingLoginListener will handle post-login redirect:
            // - Invited users: Skip onboarding (they have a company)
            // - Regular users: Redirect to onboarding
            return $this->security->login($user, 'security.authenticator.form_login.main', 'main');
        }

        return $this->render('@SolidInvoiceUser/Security/register.html.twig', ['form' => $form, 'turnstile_site_key' => $this->turnstileSiteKey]);
    }
}
