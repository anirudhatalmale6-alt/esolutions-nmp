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

namespace SolidInvoice\CoreBundle\Action\Purchase;

use Brick\Math\BigDecimal;
use SolidInvoice\CoreBundle\Repository\PurchaseRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class ListPurchases
{
    public function __construct(
        private PurchaseRepository $purchaseRepository,
    ) {
    }

    /**
     * @return array{purchases: list<\SolidInvoice\CoreBundle\Entity\Purchase>, totalPurchased: string, totalPaid: string, totalOutstanding: string}
     */
    #[Template('@SolidInvoiceCore/Purchase/list.html.twig')]
    public function __invoke(): array
    {
        $purchases = $this->purchaseRepository->findAllOrdered();

        $totalPurchased = BigDecimal::zero();
        $totalPaid = BigDecimal::zero();
        $totalOutstanding = BigDecimal::zero();

        foreach ($purchases as $purchase) {
            $totalPurchased = $totalPurchased->plus(BigDecimal::of($purchase->getTotalAmount()));
            $totalPaid = $totalPaid->plus(BigDecimal::of($purchase->getAmountPaid()));
            $totalOutstanding = $totalOutstanding->plus(BigDecimal::of($purchase->getBalance()));
        }

        return [
            'purchases' => $purchases,
            'totalPurchased' => (string) $totalPurchased->toScale(2),
            'totalPaid' => (string) $totalPaid->toScale(2),
            'totalOutstanding' => (string) $totalOutstanding->toScale(2),
        ];
    }
}
