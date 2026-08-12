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

namespace SolidInvoice\CoreBundle\Action\Report;

use Brick\Math\BigDecimal;
use SolidInvoice\CoreBundle\Receivables\ClientBalances;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Debtors / Receivables - the "who owes us what" report, laid out like the
 * Sundry Debtors group summary the accountant already works from: one line per
 * customer, a Debit column for what they owe and a Credit column for what we owe
 * them, and a grand total at the foot.
 *
 * Each customer's closing balance is built from three pieces:
 *
 *     opening balance          carried over from the old Tally ledger
 *   + unpaid invoice balances  what they have been billed here and not settled
 *   - standalone receipts      money-in recorded against them off-invoice
 *   = closing balance          positive = they owe us, negative = we owe them
 *
 * The figures themselves come from ClientBalances, which a customer's own page
 * uses too - the two screens must never disagree about what someone owes.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class Debtors
{
    public function __construct(
        private ClientBalances $clientBalances,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[Template('@SolidInvoiceCore/Report/debtors.html.twig')]
    public function __invoke(Request $request): array
    {
        // Show the settled customers only when asked - the accountant's working
        // view is the list of people who still owe something.
        $showSettled = $request->query->getBoolean('all');

        $rows = $this->clientBalances->forCompany();

        $totalDebit = BigDecimal::zero();
        $totalCredit = BigDecimal::zero();
        $settledCount = 0;
        $visible = [];

        // The rows arrive already sorted by balance, biggest debtor first.
        foreach ($rows as $row) {
            $balance = BigDecimal::of($row['balance']);

            if ($balance->isPositive()) {
                $totalDebit = $totalDebit->plus($balance);
            } elseif ($balance->isNegative()) {
                $totalCredit = $totalCredit->plus($balance->abs());
            } else {
                ++$settledCount;
            }

            if ($showSettled || ! $balance->isZero()) {
                $visible[] = $row;
            }
        }

        return [
            'rows' => $visible,
            'totalDebit' => (string) $totalDebit->toScale(2),
            'totalCredit' => (string) $totalCredit->toScale(2),
            'net' => (string) $totalDebit->minus($totalCredit)->toScale(2),
            'showSettled' => $showSettled,
            'settledCount' => $settledCount,
        ];
    }
}
