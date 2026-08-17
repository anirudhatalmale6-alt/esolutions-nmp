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

namespace SolidInvoice\UserBundle\Onboarding\Form\Step;

use SolidInvoice\MoneyBundle\Form\Type\CurrencyType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Page two of sign-up: who you are and where to find you.
 *
 * @see \SolidInvoice\UserBundle\Tests\Onboarding\Form\Step\CompanySetupStepTest
 * @extends AbstractType<array{companyName: string, fullName: string, city: string, country: string, contactNumber: string, companyCurrency: mixed}>
 */
final class CompanySetupStep extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('companyName', TextType::class, [
            'label' => 'Trading name',
            'required' => true,
            'attr' => [
                'placeholder' => 'The name your customers know you by',
                'autofocus' => true,
                'autocomplete' => 'organization',
            ],
            'help' => 'This is the name that goes on your invoices.',
        ]);

        $builder->add('fullName', TextType::class, [
            'label' => 'Your full name',
            'required' => true,
            'attr' => [
                'placeholder' => 'First and last name',
                'autocomplete' => 'name',
            ],
        ]);

        $builder->add('city', TextType::class, [
            'label' => 'City',
            'required' => true,
            'attr' => [
                'placeholder' => 'Dubai',
                'autocomplete' => 'address-level2',
            ],
        ]);

        // The country drives the currency below it (see the small script in
        // step_company.html.twig), so it is asked first - answering it fills in
        // the answer to the next question, which is one less thing to think about.
        $builder->add('country', CountryType::class, [
            'label' => 'Country',
            'required' => true,
            'placeholder' => 'Choose your country',
            'preferred_choices' => ['AE', 'SA', 'IN', 'HK', 'JP', 'CN', 'GB', 'US'],
            'attr' => [
                'autocomplete' => 'country',
                'data-country-currency' => true,
            ],
        ]);

        $builder->add('contactNumber', TelType::class, [
            'label' => 'Contact number',
            'required' => true,
            'attr' => [
                'placeholder' => '+971 50 123 4567',
                'autocomplete' => 'tel',
                'inputmode' => 'tel',
            ],
            'help' => 'Include the country code. We use this to reach you about your account.',
        ]);

        $builder->add('companyCurrency', CurrencyType::class, [
            'label' => 'Currency you invoice in',
            'required' => true,
            'attr' => [
                'data-currency-target' => true,
            ],
        ]);
    }
}
