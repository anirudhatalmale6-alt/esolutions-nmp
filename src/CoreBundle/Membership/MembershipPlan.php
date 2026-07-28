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

namespace SolidInvoice\CoreBundle\Membership;

/**
 * The three membership tiers a company (vendor) can be on.
 *
 *  - None    : brand-new / lapsed account, no paid plan. Kept as a real value so
 *              the column is never null and the super-user panel can show it.
 *  - Basic   : entry paid tier (499 AED/yr) - invoicing + internal tools only.
 *  - Premium : top paid tier (999 AED/yr) - everything in Basic PLUS the two
 *              public sales channels (Marketplace + Online Store).
 *
 * "Complimentary" is intentionally NOT a tier here - it is a way of granting a
 * tier for free (a boolean on the company), so the platform owner can hand
 * someone Premium access without a Stripe charge. See {@see MembershipManager}.
 */
enum MembershipPlan: string
{
    case None = 'none';
    case Basic = 'basic';
    case Premium = 'premium';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No plan',
            self::Basic => 'Basic',
            self::Premium => 'Premium',
        };
    }

    /**
     * A paid tier (i.e. not the empty "None" state).
     */
    public function isPaid(): bool
    {
        return $this !== self::None;
    }

    /**
     * Does this tier unlock the public sales channels (Marketplace + Online Store)?
     * Only Premium does.
     */
    public function unlocksSalesChannels(): bool
    {
        return $this === self::Premium;
    }

    /**
     * Tolerant parser - anything unrecognised (or null) resolves to None, so a
     * bad/empty column value can never take a page down.
     */
    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::None;
    }
}
