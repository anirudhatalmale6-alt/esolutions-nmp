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

use SolidInvoice\UserBundle\Validator\Constraints\UsablePassword;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;

/**
 * @see \SolidInvoice\UserBundle\Tests\DTO\ChangePasswordTest
 */
final class ChangePassword
{
    #[NotBlank]
    #[UserPassword]
    public ?string $currentPassword = null;

    #[NotBlank(message: 'Please enter a password')]
    #[Length(max: 4096)]
    // Same rule as signing up - see Registration. The old one here was stricter
    // still (STRENGTH_MEDIUM), so anyone who managed to register then could not
    // change their password to anything they would remember.
    #[UsablePassword]
    #[NotCompromisedPassword(
        message: 'This password has been leaked in a data breach, please use a different password.',
        skipOnError: true,
    )]
    public ?string $plainPassword = null;
}
