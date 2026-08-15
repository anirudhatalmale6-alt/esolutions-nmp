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
 * Why a stock quantity changed. Every movement carries one, so the stock ledger
 * reads as a plain history - "20 in from a purchase, 3 out on an invoice, 1
 * written off as damaged" - rather than an unexplained running number.
 */
enum StockMovementReason: string
{
    /** The one-time starting quantity, taken from the final Tally import. */
    case Baseline = 'baseline';

    /** Goods bought in, from a purchase order. */
    case Purchase = 'purchase';

    /** Goods sold, from an invoice. */
    case Sale = 'sale';

    /** A sale reversed - credit note, refund or customer return. */
    case Return = 'return';

    /** A purchase reversed - goods sent back to the supplier. */
    case PurchaseReturn = 'purchase_return';

    /** Counted by hand: stock-take correction, damage, loss or theft. */
    case Adjustment = 'adjustment';

    /**
     * Moved between grades on the same item - stock received as A that turns
     * out to be C. Nothing enters or leaves the building, so these always come
     * in pairs that cancel out on the item's total.
     */
    case Regrade = 'regrade';

    public function label(): string
    {
        return match ($this) {
            self::Baseline => 'Opening stock',
            self::Purchase => 'Purchase',
            self::Sale => 'Sale',
            self::Return => 'Customer return',
            self::PurchaseReturn => 'Returned to supplier',
            self::Adjustment => 'Manual adjustment',
            self::Regrade => 'Regraded',
        };
    }

    /**
     * Whether this reason normally adds stock. Used for the default sign and to
     * colour the movement in the stock history.
     */
    public function isInbound(): bool
    {
        return match ($this) {
            self::Baseline, self::Purchase, self::Return => true,
            self::Sale, self::PurchaseReturn => false,
            // An adjustment or a regrade can go either way, so both follow the
            // sign that was entered.
            self::Adjustment, self::Regrade => true,
        };
    }

    public function colour(): string
    {
        return match ($this) {
            self::Baseline => 'secondary',
            self::Purchase, self::Return => 'green',
            self::Sale, self::PurchaseReturn => 'blue',
            self::Adjustment => 'orange',
            self::Regrade => 'purple',
        };
    }
}
