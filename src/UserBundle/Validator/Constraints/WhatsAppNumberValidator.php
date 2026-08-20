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

use SolidInvoice\CoreBundle\WhatsApp\WhatsAppSender;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use function is_string;
use function trim;

/**
 * Accepts exactly what the gateway can send to, and nothing else.
 *
 * The test is the same call the sender makes, rather than a regular expression
 * that agrees with it today and drifts tomorrow - if this passes, the message
 * can be addressed.
 *
 * Blank is left to NotBlank so somebody who simply skipped the field is told
 * that, not told their empty box is a badly formatted number.
 */
final class WhatsAppNumberValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (! $constraint instanceof WhatsAppNumber) {
            throw new UnexpectedTypeException($constraint, WhatsAppNumber::class);
        }

        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            throw new UnexpectedTypeException($value, 'string');
        }

        if (trim($value) === '') {
            return;
        }

        if (WhatsAppSender::chatId($value) === null) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
