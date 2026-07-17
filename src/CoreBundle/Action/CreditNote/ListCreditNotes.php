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

namespace SolidInvoice\CoreBundle\Action\CreditNote;

use Brick\Math\BigDecimal;
use SolidInvoice\CoreBundle\Entity\CreditNote;
use SolidInvoice\CoreBundle\Repository\CreditNoteRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class ListCreditNotes
{
    public function __construct(
        private CreditNoteRepository $creditNoteRepository,
    ) {
    }

    /**
     * @return array{creditNotes: list<CreditNote>, totalCash: string, totalCredit: string}
     */
    #[Template('@SolidInvoiceCore/CreditNote/list.html.twig')]
    public function __invoke(): array
    {
        $creditNotes = $this->creditNoteRepository->findAllOrdered();

        $totalCash = BigDecimal::zero();
        $totalCredit = BigDecimal::zero();

        foreach ($creditNotes as $creditNote) {
            $amount = BigDecimal::of($creditNote->getAmount());

            if ($creditNote->isStoreCredit()) {
                $totalCredit = $totalCredit->plus($amount);
            } else {
                $totalCash = $totalCash->plus($amount);
            }
        }

        return [
            'creditNotes' => $creditNotes,
            'totalCash' => (string) $totalCash->toScale(2),
            'totalCredit' => (string) $totalCredit->toScale(2),
        ];
    }
}
