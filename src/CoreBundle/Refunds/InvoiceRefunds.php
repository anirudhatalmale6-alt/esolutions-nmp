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

namespace SolidInvoice\CoreBundle\Refunds;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use Throwable;
use function strlen;
use function strtolower;
use function substr;

/**
 * Whether an invoice has been refunded, and by how much.
 *
 * A refund never un-pays an invoice - the customer paid it, and the money was
 * then handed back, and the record keeps both facts - so a refunded invoice
 * still reads "Paid". That is correct, but on its own it looks exactly like an
 * ordinary completed sale, so the invoice page and the invoice list both mark
 * refunded invoices. They ask here, so the two screens cannot disagree about
 * what counts as fully refunded.
 *
 * Credit note amounts are stored in MAJOR units (AED) while invoice totals are
 * in MINOR units (fils), so amounts are scaled by 100 before they are compared.
 */
final class InvoiceRefunds
{
    public const string NONE = 'none';

    public const string PARTIAL = 'partial';

    public const string FULL = 'full';

    /**
     * invoice id (lowercase hex) => total refunded against it, in minor units.
     * Filled on first use and kept for the rest of the request, so a grid page
     * costs one query no matter how many rows it shows.
     *
     * @var array<string, string>|null
     */
    private ?array $refundedByInvoice = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * NONE, PARTIAL or FULL for a single invoice.
     */
    public function stateFor(Invoice $invoice): string
    {
        $id = $invoice->getId();

        if ($id === null) {
            return self::NONE;
        }

        $refunded = $this->all()[strtolower($id->toRfc4122())] ?? null;

        if ($refunded === null) {
            return self::NONE;
        }

        return $this->state(BigInteger::of($refunded), $invoice->getTotal());
    }

    /**
     * The rule itself, split out so a caller that has already summed the credit
     * notes (the invoice page loads them anyway, to list them) applies exactly
     * the same test rather than a second copy of it.
     *
     * @param BigInteger $refundedMinor total refunded, in minor units
     * @param BigNumber  $totalMinor    the invoice total, in minor units
     */
    public function state(BigInteger $refundedMinor, BigNumber $totalMinor): string
    {
        if ($refundedMinor->isNegativeOrZero()) {
            return self::NONE;
        }

        $total = $totalMinor->toBigInteger();

        // A zero-total invoice with a refund on it can only be fully refunded.
        if ($total->isNegativeOrZero()) {
            return self::FULL;
        }

        return $refundedMinor->isGreaterThanOrEqualTo($total) ? self::FULL : self::PARTIAL;
    }

    /**
     * Every invoice that has a refund against it, keyed by invoice id.
     *
     * Deliberately one aggregate over the whole credit_note table rather than a
     * lookup per row: the invoice list would otherwise fire a query per line.
     * Refunds are rare next to invoices, so the summed table stays small. No
     * company filter is needed - the key is a ULID, so it cannot collide across
     * companies, and an invoice only ever finds its own refunds.
     *
     * @return array<string, string>
     */
    private function all(): array
    {
        if ($this->refundedByInvoice !== null) {
            return $this->refundedByInvoice;
        }

        $this->refundedByInvoice = [];

        try {
            $rows = $this->connection->executeQuery(
                'SELECT LOWER(HEX(invoice_id)) AS invoiceId, SUM(amount) AS refunded
                 FROM credit_note
                 GROUP BY invoice_id'
            )->fetchAllAssociative();
        } catch (Throwable) {
            // No credit_note table yet (migration not run on this environment).
            // A missing refund marker must never take the invoice list down.
            return $this->refundedByInvoice;
        }

        foreach ($rows as $row) {
            $hex = strtolower((string) $row['invoiceId']);

            if ($hex === '') {
                continue;
            }

            $this->refundedByInvoice[$this->asUuid($hex)] = (string) BigDecimal::of((string) ($row['refunded'] ?? '0'))
                ->multipliedBy(100)
                ->toScale(0, RoundingMode::HalfUp)
                ->toBigInteger();
        }

        return $this->refundedByInvoice;
    }

    /**
     * LOWER(HEX(id)) gives 32 bare hex characters; Ulid::toRfc4122() gives the
     * dashed form. Hyphenate so the two line up as array keys.
     */
    private function asUuid(string $hex): string
    {
        if (strlen($hex) !== 32) {
            return $hex;
        }

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
