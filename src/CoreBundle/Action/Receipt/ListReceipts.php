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

namespace SolidInvoice\CoreBundle\Action\Receipt;

use Brick\Math\BigDecimal;
use SolidInvoice\CoreBundle\Repository\CustomerReceiptRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class ListReceipts
{
    public function __construct(
        private CustomerReceiptRepository $receiptRepository,
    ) {
    }

    /**
     * @return array{receipts: list<\SolidInvoice\CoreBundle\Entity\CustomerReceipt>, totalReceipts: string}
     */
    #[Template('@SolidInvoiceCore/Receipt/list.html.twig')]
    public function __invoke(): array
    {
        $receipts = $this->receiptRepository->findAllOrdered();

        $total = BigDecimal::zero();

        foreach ($receipts as $receipt) {
            $total = $total->plus(BigDecimal::of($receipt->getAmount()));
        }

        return [
            'receipts' => $receipts,
            'totalReceipts' => (string) $total->toScale(2),
        ];
    }
}
