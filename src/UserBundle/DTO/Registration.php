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

namespace SolidInvoice\UserBundle\DTO;

use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Validator\Constraints\UniqueWhatsAppNumber;
use SolidInvoice\UserBundle\Validator\Constraints\UsablePassword;
use SolidInvoice\UserBundle\Validator\Constraints\WhatsAppNumber;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

#[UniqueEntity(['email'], entityClass: User::class)]
final class Registration
{
    #[
        NotBlank,
        Email(['mode' => Email::VALIDATION_MODE_STRICT]),
    ]
    public ?string $email = null;

    // PasswordStrength scored on entropy alone and turned away anything short,
    // however it was built - "Nmp@2026" included - so people gave up on signing
    // up. UsablePassword asks for length and variety instead, and says which of
    // the two is missing.
    #[
        NotBlank(message: 'Please enter a password'),
        Length(
            max: 4096,
            // max length allowed by Symfony for security reasons
        ),
        UsablePassword(emailProperty: 'email')]
    public ?string $plainPassword = null;

    /**
     * The WhatsApp number, which is how the account is really verified.
     *
     * Email is kept as the identity - it is what people log in with, and what a
     * password reset needs - but an email address costs nothing to invent, and
     * that is how the same person kept arriving as several businesses. A number
     * that has to receive a code is real effort to duplicate.
     *
     * Stored on User::mobile, which already existed on the platform's user model
     * and was only ever editable from the profile page, so there is no migration
     * behind this.
     */
    #[
        NotBlank(message: 'Please enter your WhatsApp number'),
        WhatsAppNumber,
        UniqueWhatsAppNumber,
    ]
    public ?string $mobile = null;

    #[IsTrue(message: 'You must accept the terms and conditions to register')]
    public ?bool $acceptTerms = null;
}
