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
 * Fulfilment stage of a MobilesOnline retail order, as it moves from the order
 * team entering it through to despatch and delivery. Cancelled sits outside the
 * forward pipeline.
 */
enum OrderStatus: string
{
    case New = 'new';
    case Confirmed = 'confirmed';
    case Packed = 'packed';
    case Despatched = 'despatched';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Confirmed => 'Confirmed',
            self::Packed => 'Packed',
            self::Despatched => 'Despatched',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Tabler badge colour used to show the status in the orders list.
     */
    public function color(): string
    {
        return match ($this) {
            self::New => 'blue',
            self::Confirmed => 'cyan',
            self::Packed => 'yellow',
            self::Despatched => 'purple',
            self::Delivered => 'green',
            self::Cancelled => 'secondary',
        };
    }

    /**
     * The forward fulfilment pipeline, in order. Cancelled is excluded - it is
     * an exit state reachable from any stage, not a step in the flow.
     *
     * @return list<self>
     */
    public static function pipeline(): array
    {
        return [self::New, self::Confirmed, self::Packed, self::Despatched, self::Delivered];
    }
}
