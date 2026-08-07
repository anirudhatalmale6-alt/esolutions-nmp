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

use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\UserBundle\Action\Register;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Onboarding\DTO\OnboardingData;
use SolidInvoice\UserBundle\Onboarding\Form\Type\OnboardingType;
use SolidInvoice\UserBundle\Onboarding\Manager\OnboardingManager;
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
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // If already completed, redirect to dashboard
        if ($this->onboardingManager->isOnboardingComplete($user)) {
            return $this->redirectToRoute('_dashboard');
        }

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
            if ($form->getCursor()->getCurrentStep() === 'invoice') {
                $formData = $form->getData();
                assert($formData instanceof OnboardingData);

                // If we have invoice data, complete onboarding immediately and redirect to invoice
                if ($formData->invoiceDescription && $formData->invoiceAmount) {
                    [$referralCode, $referralName] = $this->consumeReferral($request);
                    $invoice = $this->onboardingManager->completeOnboarding($user, $formData, $referralCode, $referralName);
                    $form->reset();

                    if ($invoice instanceof Invoice) {
                        $this->addFlash('success', 'onboarding.flash.invoice_created');
                        return $this->redirectToRoute('_invoices_view', ['id' => $invoice->getId()]);
                    }
                }
            } elseif ($form->isFinished()) {
                $formData = $form->getData();
                assert($formData instanceof OnboardingData);

                // Save all data and get created invoice
                [$referralCode, $referralName] = $this->consumeReferral($request);
                $invoice = $this->onboardingManager->completeOnboarding($user, $formData, $referralCode, $referralName);

                // Clear form data from session
                $form->reset();

                // If an invoice was created, redirect to invoice detail page
                if ($invoice instanceof Invoice) {
                    $this->addFlash('success', 'onboarding.flash.invoice_created');
                    return $this->redirectToRoute('_invoices_view', ['id' => $invoice->getId()]);
                }

                // Otherwise, redirect to dashboard
                $this->addFlash('success', 'onboarding.flash.onboarding_complete');
                return $this->redirectToRoute('_dashboard');
            } else {
                $this->onboardingManager->setCurrentStep($user, $form->getCursor()->getCurrentStep());
            }
        }

        $formData = $form->getData();
        assert($formData instanceof OnboardingData);

        // Render current step
        return $this->render('@SolidInvoiceUser/Onboarding/onboarding.html.twig', [
            'form' => $form->getStepForm(),
            'currentStep' => $form->getCursor()->getCurrentStep(),
            'progress' => $this->calculateProgress($form),
            'hasClient' => $formData->clientName !== null && $formData->clientName !== '',
        ]);
    }

    /**
     * Pull the referral (rep code + name) captured at registration out of the
     * session and clear it, so the company created during onboarding can be stamped
     * exactly once. Returns [code, name], both null when this was not a referral
     * signup (e.g. the platform owner's own company).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function consumeReferral(Request $request): array
    {
        $session = $request->getSession();

        $code = $session->get(Register::SESSION_REFERRAL_CODE);
        $name = $session->get(Register::SESSION_REFERRAL_NAME);

        $session->remove(Register::SESSION_REFERRAL_CODE);
        $session->remove(Register::SESSION_REFERRAL_NAME);

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
