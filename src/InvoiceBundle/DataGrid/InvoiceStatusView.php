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

namespace SolidInvoice\InvoiceBundle\DataGrid;

use SolidInvoice\CoreBundle\Refunds\InvoiceRefunds;
use Stringable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Display value for the invoice list "Status" column. It carries a human label
 * and a Tabler colour so the grid can render a coloured badge, while its string
 * form (used by CSV/data export) stays a plain label like "Partially Paid".
 */
#[Exclude]
final readonly class InvoiceStatusView implements Stringable
{
    /**
     * @param string $refund InvoiceRefunds::NONE, PARTIAL or FULL. A refunded
     *                       invoice still reads "Paid" - correctly, the customer
     *                       did pay it - so the grid puts a coloured dot beside
     *                       the status to flag that money went back out. Kept out
     *                       of __toString so the CSV export is unaffected.
     */
    public function __construct(
        public string $name,
        public string $color,
        public string $refund = InvoiceRefunds::NONE,
    ) {
    }

    public function withRefund(string $refund): self
    {
        return new self($this->name, $this->color, $refund);
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
