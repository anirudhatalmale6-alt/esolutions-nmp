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

namespace SolidInvoice\UserBundle\Onboarding\Action;

use SolidInvoice\UserBundle\Action\Register;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Enum\UserSettingType;
use SolidInvoice\UserBundle\Onboarding\DTO\OnboardingData;
use SolidInvoice\UserBundle\Onboarding\Form\Type\OnboardingType;
use SolidInvoice\UserBundle\Onboarding\Manager\OnboardingManager;
use SolidInvoice\UserBundle\Repository\UserSettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function assert;
use function is_string;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class Onboarding extends AbstractController
{
    public function __construct(
        private readonly OnboardingManager $onboardingManager,
        private readonly UserSettingRepository $userSettingRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // This page exists to create the ONE business behind a new account, so an
        // account that already has one never sees it again. Without this, a user
        // who reached the portal some other way and made a company there could
        // come back to a half-finished onboarding session and finish it, ending up
        // with the same business on the platform twice under one e-mail address.
        // Extra businesses are added deliberately from Create company instead.
        if (count($user->getCompanies()) > 0) {
            if (! $this->onboardingManager->isOnboardingComplete($user)) {
                $this->onboardingManager->dismissOnboarding($user);
            }

            return $this->redirectToRoute('_dashboard');
        }

        // No company. Whatever the "complete" flag says, there is nothing to use
        // the portal with until this form is filled in, so it is shown again -
        // an account whose company was deleted from the Memberships console keeps
        // the flag, and sending it to the dashboard would bounce it straight back
        // here for ever.

        // Initialize onboarding if not started
        $currentStep = $this->onboardingManager->getCurrentStep($user);

        if (! $currentStep) {
            $this->onboardingManager->startOnboarding($user);
        }

        // Create and handle form
        $form = $this->createForm(OnboardingType::class, new OnboardingData())
            ->handleRequest($request);

        assert($form instanceof FormFlowInterface);

        // Check if we're on the complete step after invoice submission (auto-complete with invoice data)

        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->isFinished()) {
                $formData = $form->getData();
                assert($formData instanceof OnboardingData);

                [$referralCode, $referralName] = $this->consumeReferral($request, $user);
                $this->onboardingManager->completeOnboarding($user, $formData, $referralCode, $referralName);

                // Clear form data from session
                $form->reset();

                // Straight to the dashboard. It used to land on a freshly created
                // invoice, which was the wrong first thing to see - the account
                // has no customers, no stock and nothing to bill for yet.
                $this->addFlash('success', 'onboarding.flash.onboarding_complete');

                return $this->redirectToRoute('_dashboard');
            }

            $this->onboardingManager->setCurrentStep($user, $form->getCursor()->getCurrentStep());
        }

        // Render current step
        return $this->render('@SolidInvoiceUser/Onboarding/onboarding.html.twig', [
            'form' => $form->getStepForm(),
            'currentStep' => $form->getCursor()->getCurrentStep(),
            'progress' => $this->calculateProgress($form),
        ]);
    }

    /**
     * Pull the referral (rep code + name) captured at registration out of the
     * session and clear it, so the company created during onboarding can be stamped
     * exactly once. Returns [code, name], both null when this was not a referral
     * signup (e.g. the platform owner's own company).
     *
     * The session is only the fast path. It is emptied when the browser is closed,
     * and somebody who signed up in the evening and came back the next morning was
     * then treated as an unreferred stranger: no rep against their business and no
     * Basic plan, so they finished sign-up and landed straight on the "pending
     * approval" page. The same two values are written onto the account itself at
     * registration, and that copy is what makes this survive.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function consumeReferral(Request $request, User $user): array
    {
        $session = $request->getSession();

        $code = $session->get(Register::SESSION_REFERRAL_CODE);
        $name = $session->get(Register::SESSION_REFERRAL_NAME);

        $session->remove(Register::SESSION_REFERRAL_CODE);
        $session->remove(Register::SESSION_REFERRAL_NAME);

        if (! is_string($code) || $code === '') {
            $code = $this->userSettingRepository->getSetting($user, UserSettingType::ReferralCode)?->getValue();
            $name = $this->userSettingRepository->getSetting($user, UserSettingType::ReferralName)?->getValue();
        }

        // Used up either way: this account gets stamped once, here.
        $this->userSettingRepository->removeSetting($user, UserSettingType::ReferralCode);
        $this->userSettingRepository->removeSetting($user, UserSettingType::ReferralName);

        return [
            is_string($code) && $code !== '' ? $code : null,
            is_string($name) && $name !== '' ? $name : null,
        ];
    }

    /**
     * Calculate progress percentage
     */
    private function calculateProgress(FormFlowInterface $form): int
    {
        $cursor = $form->getCursor();
        $totalSteps = count($cursor->getSteps());
        $currentPosition = $cursor->getStepIndex();

        return (int) (($currentPosition / $totalSteps) * 100);
    }
}
