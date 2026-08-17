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

namespace SolidInvoice\CoreBundle\Enum;

/**
 * What sort of thing a member is telling us about.
 *
 * Four, and no more. A long list makes people stop and think about which box
 * their problem goes in, which is a good way to make them close the tab instead.
 */
enum SupportTicketKind: string
{
    case Bug = 'bug';

    case Problem = 'problem';

    case Question = 'question';

    case Idea = 'idea';

    public function label(): string
    {
        return match ($this) {
            self::Bug => 'Something is broken',
            self::Problem => 'I am stuck',
            self::Question => 'A question',
            self::Idea => 'An idea',
        };
    }

    /**
     * Anything unrecognised reads as a plain problem rather than blowing up a
     * page - a bad value in that column must never take the list down.
     */
    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Problem;
    }
}
