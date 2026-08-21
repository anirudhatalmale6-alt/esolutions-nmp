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

namespace SolidInvoice\UserBundle\Validator\Constraints;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * One WhatsApp number, one account.
 *
 * The whole reason sign-up asks for a number is that an email address costs
 * nothing to invent, which is how the same person kept arriving as several
 * businesses. That only holds if the number cannot simply be reused - otherwise
 * the second sign-up is exactly as cheap as it was before.
 *
 * Deliberately not UniqueEntity: that compares the column as text, and the same
 * phone written +971 50 123 4567, 00971501234567 and 971501234567 is three
 * different strings and one telephone.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class UniqueWhatsAppNumber extends Constraint
{
    public string $message = 'This WhatsApp number is already registered. Sign in with that account, or use a different number.';
}
