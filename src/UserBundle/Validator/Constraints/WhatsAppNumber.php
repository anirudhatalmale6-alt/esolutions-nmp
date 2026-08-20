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
 * A number WhatsApp could actually deliver to.
 *
 * Not a general phone-number check: the only question that matters is whether
 * the gateway can turn it into a chat id. A number that is almost right is
 * worse than one that is obviously wrong, because the send succeeds, the
 * message goes nowhere, and it looks exactly like somebody ignoring their
 * verification.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class WhatsAppNumber extends Constraint
{
    public string $message = 'Please enter your number with the country code, for example 971501234567 or 447700900123.';
}
