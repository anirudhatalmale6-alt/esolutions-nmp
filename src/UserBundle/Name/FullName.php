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

namespace SolidInvoice\UserBundle\Name;

use SolidInvoice\UserBundle\Entity\User;
use function mb_strrpos;
use function mb_substr;
use function preg_replace;
use function trim;

/**
 * One box on a form, two columns in the database.
 *
 * People write their name the way they say it, so the split is the last space:
 * everything before it is the first name, the remainder is the surname. A single
 * word (some members go by one name) becomes the first name with no surname
 * rather than being rejected - the form already made sure something was typed,
 * and arguing about the shape of a stranger's name is not sign-up's job.
 *
 * Lives here rather than inside the onboarding manager because more than one
 * page now asks for it: sign-up, and the "we still need your details" form a
 * member fills in when their account was created without them.
 *
 * @see \SolidInvoice\UserBundle\Tests\Name\FullNameTest
 */
final class FullName
{
    public static function applyTo(User $user, ?string $fullName): void
    {
        $fullName = self::normalise($fullName);

        if ($fullName === '') {
            return;
        }

        $split = mb_strrpos($fullName, ' ');

        if ($split === false) {
            $user->setFirstName($fullName);

            return;
        }

        $user->setFirstName(mb_substr($fullName, 0, $split));
        $user->setLastName(mb_substr($fullName, $split + 1));
    }

    /**
     * The name as one string again, for putting back in the box. Empty when we
     * never got one.
     */
    public static function of(User $user): string
    {
        return self::normalise(trim($user->getFirstName() . ' ' . $user->getLastName()));
    }

    private static function normalise(?string $fullName): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $fullName));
    }
}
