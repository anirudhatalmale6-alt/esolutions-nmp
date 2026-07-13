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

namespace SolidInvoice\CoreBundle\Action\Stock;

use Brick\Math\BigDecimal;
use SolidInvoice\CoreBundle\Repository\StockModelRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class ListStock
{
    public function __construct(
        private StockModelRepository $stockModelRepository,
    ) {
    }

    /**
     * @return array{models: list<\SolidInvoice\CoreBundle\Entity\StockModel>, totalQuantity: int, totalValue: string}
     */
    #[Template('@SolidInvoiceCore/Stock/list.html.twig')]
    public function __invoke(): array
    {
        $models = $this->stockModelRepository->findAllOrdered();

        $totalQuantity = 0;
        $totalValue = BigDecimal::zero();

        foreach ($models as $model) {
            $totalQuantity += $model->getQuantity();
            $totalValue = $totalValue->plus(BigDecimal::of($model->getValue()));
        }

        return [
            'models' => $models,
            'totalQuantity' => $totalQuantity,
            'totalValue' => (string) $totalValue->toScale(2),
        ];
    }
}
