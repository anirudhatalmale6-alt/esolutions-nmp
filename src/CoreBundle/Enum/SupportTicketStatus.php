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

enum SupportTicketStatus: string
{
    case Open = 'open';

    /** Being worked on - shown to the member so silence does not read as ignored. */
    case InProgress = 'in_progress';

    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'Being looked at',
            self::Closed => 'Closed',
        };
    }

    /** Tabler badge colour for this state. */
    public function badge(): string
    {
        return match ($this) {
            self::Open => 'bg-blue-lt',
            self::InProgress => 'bg-yellow-lt',
            self::Closed => 'bg-secondary-lt',
        };
    }

    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Open;
    }
}
