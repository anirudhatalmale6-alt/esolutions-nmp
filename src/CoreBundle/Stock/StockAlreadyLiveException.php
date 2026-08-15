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

namespace SolidInvoice\CoreBundle\Stock;

use RuntimeException;
use function sprintf;

/**
 * Thrown when a Tally import would overwrite quantities the system has been
 * counting itself.
 *
 * Once invoices and purchases start moving stock, the app knows things Tally
 * does not - today's sales, today's arrivals. An import at that point silently
 * throws those away, and nobody notices until a customer is promised a phone
 * that is not there. So it is refused, and the user has to say plainly that
 * they want to start again from the sheet.
 */
final class StockAlreadyLiveException extends RuntimeException
{
    public function __construct(
        public readonly int $movementCount,
    ) {
        parent::__construct(sprintf(
            'Stock is being counted live: %d movement(s) have been recorded from invoices, purchases or corrections since the opening figure was set. Importing again would discard them.',
            $movementCount,
        ));
    }
}
