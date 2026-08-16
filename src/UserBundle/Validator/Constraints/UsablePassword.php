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
 * A password rule people can actually satisfy.
 *
 * Symfony's PasswordStrength scores on entropy alone, so it rejects everything
 * short no matter how it is built - "Nmp@2026" (upper + lower + digit + symbol)
 * scores zero, and the only way through is a long passphrase. Traders signing up
 * hit that wall and gave up, which is worse for security than a rule they will
 * follow.
 *
 * So the bar here is length plus variety plus a few outright refusals, and every
 * refusal says exactly what is wrong.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class UsablePassword extends Constraint
{
    public string $shortMessage = 'Please use at least {{ limit }} characters.';

    public string $varietyMessage = 'Please mix at least two kinds of character - for example letters and numbers, or letters and a symbol.';

    public string $commonMessage = 'That password is one of the most commonly used ones, so it is the first thing an attacker tries. Please pick another.';

    public string $repeatMessage = 'A password made of one repeated character is too easy to guess. Please pick another.';

    public string $emailMessage = 'Please do not use your own email name as your password.';

    public int $min = 10;

    /**
     * Name of a property on the same object holding the user's email address, so
     * the password can be refused for being that name with a few digits after
     * it. Left null when there is nothing to compare against.
     */
    public ?string $emailProperty = null;

    public function __construct(
        ?int $min = null,
        ?string $emailProperty = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct([], $groups, $payload);

        $this->min = $min ?? $this->min;
        $this->emailProperty = $emailProperty;
    }
}
