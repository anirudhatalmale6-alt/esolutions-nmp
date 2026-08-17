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

namespace SolidInvoice\UserBundle\Onboarding\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the second page of sign-up asks for.
 *
 * Registration itself takes an email and a password and nothing else - the point
 * of that page is to get somebody through the door. This is the page after it,
 * and it collects the things every account was missing: who they trade as, who
 * they are, where they are, and a number to reach them on.
 *
 * It used to ask them to add a client and write their first invoice as well.
 * That was dropped: a new member has no idea yet what this is, and being asked
 * to invoice a customer in the first two minutes is a reason to close the tab.
 */
final class OnboardingData
{
    public ?string $currentStep = null;

    #[Assert\NotBlank(groups: ['company'], message: 'Please enter the name you trade under.')]
    #[Assert\Length(max: 191, groups: ['company'])]
    public ?string $companyName = null;

    #[Assert\NotBlank(groups: ['company'], message: 'Please enter your full name.')]
    #[Assert\Length(max: 90, groups: ['company'])]
    public ?string $fullName = null;

    #[Assert\NotBlank(groups: ['company'], message: 'Please enter the city you trade from.')]
    #[Assert\Length(max: 100, groups: ['company'])]
    public ?string $city = null;

    #[Assert\NotBlank(groups: ['company'], message: 'Please choose your country.')]
    #[Assert\Country(groups: ['company'])]
    public ?string $country = null;

    /**
     * Full international form, e.g. +971 50 123 4567. Kept as free text with a
     * shape check rather than split into a dial-code select and a number: people
     * paste the whole thing out of WhatsApp, and a split field throws that away.
     */
    #[Assert\NotBlank(groups: ['company'], message: 'Please enter a contact number, including the country code.')]
    #[Assert\Regex(
        pattern: '/^\+[1-9]\d{0,3}[\s\-]?[\d\s\-]{5,17}$/',
        message: 'Please enter the number with its country code, like +971 50 123 4567.',
        groups: ['company'],
    )]
    public ?string $contactNumber = null;

    #[Assert\NotBlank(groups: ['company'])]
    #[Assert\Currency(groups: ['company'])]
    public ?string $companyCurrency = 'AED';
}
