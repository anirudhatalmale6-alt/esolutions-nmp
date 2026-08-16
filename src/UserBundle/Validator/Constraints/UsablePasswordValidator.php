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

use Symfony\Component\PropertyAccess\Exception\ExceptionInterface as PropertyAccessException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use function count;
use function explode;
use function is_string;
use function mb_strlen;
use function mb_strtolower;
use function preg_match;
use function preg_replace;
use function str_contains;
use function str_starts_with;
use function strtr;
use function trim;

/**
 * @see \SolidInvoice\UserBundle\Tests\Validator\Constraints\UsablePasswordValidatorTest
 */
final class UsablePasswordValidator extends ConstraintValidator
{
    /** Long enough that it does not have to be a mixture as well. */
    private const int PASSPHRASE_LENGTH = 16;

    /**
     * The passwords that actually turn up at the top of every breach dump, plus
     * the ones a phone trader reaches for first. Compared after digits and
     * symbols are stripped, so "password123" and "p@ssword" are caught too.
     *
     * @var list<string>
     */
    private const array COMMON = [
        'password', 'passwort', 'pass', 'welcome', 'letmein', 'admin', 'administrator',
        'qwerty', 'qwertyuiop', 'asdf', 'asdfgh', 'zxcvbn', 'abc', 'abcd', 'abcdef',
        'iloveyou', 'monkey', 'dragon', 'sunshine', 'princess', 'football', 'baseball',
        'master', 'shadow', 'superman', 'trustno', 'whatever', 'freedom', 'starwars',
        'login', 'test', 'testing', 'demo', 'guest', 'user', 'root', 'secret',
        'mobile', 'mobiles', 'phone', 'iphone', 'samsung', 'invoice', 'company',
    ];

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (! $constraint instanceof UsablePassword) {
            throw new UnexpectedTypeException($constraint, UsablePassword::class);
        }

        // Blank is NotBlank's business, not ours.
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            throw new UnexpectedTypeException($value, 'string');
        }

        if (mb_strlen($value) < $constraint->min) {
            $this->context->buildViolation($constraint->shortMessage)
                ->setParameter('{{ limit }}', (string) $constraint->min)
                ->addViolation();

            return;
        }

        // A long passphrase is strong because it is long. Asking it for a digit
        // as well only makes people write it down.
        if (mb_strlen($value) < self::PASSPHRASE_LENGTH && $this->kinds($value) < 2) {
            $this->context->buildViolation($constraint->varietyMessage)->addViolation();

            return;
        }

        // "aaaaaaaaaa" and "111111111111" clear both tests above on length alone.
        if (preg_match('/^(.)\1*$/u', $value) === 1) {
            $this->context->buildViolation($constraint->repeatMessage)->addViolation();

            return;
        }

        $normalised = $this->normalise($value);

        foreach (self::COMMON as $word) {
            if ($this->looksLike($normalised, $word)) {
                $this->context->buildViolation($constraint->commonMessage)->addViolation();

                return;
            }
        }

        $emailName = $this->emailName($constraint);

        if ($emailName !== null && $this->looksLike($normalised, $emailName)) {
            $this->context->buildViolation($constraint->emailMessage)->addViolation();
        }
    }

    /**
     * Is this password essentially that word? Sticking a couple of digits on the
     * end - "password12", "trader1234" - is the oldest trick there is and buys
     * nothing, so a short tail still counts as the word itself. Anything longer
     * than that has real material in it and is left alone: "nmpmobiles26" is not
     * "mobiles", and "admin" being buried inside a longer password is fine.
     */
    private function looksLike(string $normalised, string $word): bool
    {
        if ($normalised === $word) {
            return true;
        }

        return str_starts_with($normalised, $word) && mb_strlen($normalised) - mb_strlen($word) <= 3;
    }

    /**
     * How many kinds of character are in play: lower case, upper case, digits,
     * anything else. Two is the bar - "nmpmobiles26" and "Nmp Mobiles" both make
     * it, "nmpmobiles" does not.
     */
    private function kinds(string $value): int
    {
        $kinds = 0;

        foreach (['/\p{Ll}/u', '/\p{Lu}/u', '/\d/u', '/[^\p{L}\d]/u'] as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                ++$kinds;
            }
        }

        return $kinds;
    }

    /**
     * The password reduced to plain lower-case letters, with the usual
     * substitutions folded back, so "P@ssw0rd" and "password" come out the same.
     *
     * The trailing run of digits and punctuation is dropped BEFORE folding -
     * otherwise the "12" people tack on the end turns into letters of its own
     * and hides the word underneath it.
     */
    private function normalise(string $value): string
    {
        $trimmed = (string) preg_replace('/[^\p{L}]+$/u', '', mb_strtolower($value));

        $folded = strtr($trimmed, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i']);

        return (string) preg_replace('/[^a-z]/', '', $folded);
    }

    /**
     * The part of the sign-up email before the @, when the constraint was told
     * where to find it and the address looks like one.
     */
    private function emailName(UsablePassword $constraint): ?string
    {
        if ($constraint->emailProperty === null) {
            return null;
        }

        $object = $this->context->getObject();

        if ($object === null) {
            return null;
        }

        try {
            $email = PropertyAccess::createPropertyAccessor()->getValue($object, $constraint->emailProperty);
        } catch (PropertyAccessException) {
            return null;
        }

        if (! is_string($email) || ! str_contains($email, '@')) {
            return null;
        }

        $parts = explode('@', trim($email));

        if (count($parts) !== 2) {
            return null;
        }

        $name = (string) preg_replace('/[^a-z]/', '', mb_strtolower($parts[0]));

        return mb_strlen($name) >= 4 ? $name : null;
    }
}
