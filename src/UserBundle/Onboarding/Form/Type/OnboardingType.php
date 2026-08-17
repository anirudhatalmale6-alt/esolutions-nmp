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

namespace SolidInvoice\UserBundle\Onboarding\Form\Type;

use SolidInvoice\UserBundle\Onboarding\DTO\OnboardingData;
use SolidInvoice\UserBundle\Onboarding\Form\FormFlow\OnboardingNavigatorType;
use SolidInvoice\UserBundle\Onboarding\Form\Step\CompanySetupStep;
use Symfony\Component\Form\Flow\AbstractFlowType;
use Symfony\Component\Form\Flow\DataStorage\SessionDataStorage;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class OnboardingType extends AbstractFlowType
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function buildFormFlow(FormFlowBuilderInterface $builder, array $options): void
    {
        // Step 1: the business profile (required). Everything the account was
        // missing - trading name, who they are, where they are, how to reach them.
        $builder->addStep(
            name: 'company',
            type: CompanySetupStep::class,
            options: [
                'inherit_data' => true,
                'required' => true,
            ],
        );

        // Step 2: done. Adding a client and writing a first invoice used to live
        // between these two; both are gone. Somebody who joined ninety seconds
        // ago does not yet know what this is, and asking them to invoice a real
        // customer before they have looked around is how you lose them.
        //
        // Identity documents are not a step here either. They are optional by
        // design, and an upload cannot survive being carried through a
        // multi-page flow in the session - so the trusted badge is its own page
        // (_verification), offered on the way out and available forever after.
        $builder->addStep(
            name: 'complete',
            options: ['mapped' => false]
        );

        $builder->add(
            'navigator',
            OnboardingNavigatorType::class,
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OnboardingData::class,
            'data_storage' => new SessionDataStorage('user_onboarding', $this->requestStack),
            'step_property_path' => 'currentStep',
        ]);
    }
}
