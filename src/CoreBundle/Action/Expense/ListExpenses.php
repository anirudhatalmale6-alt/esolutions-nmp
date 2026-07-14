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

namespace SolidInvoice\CoreBundle\Action\Expense;

use Brick\Math\BigDecimal;
use SolidInvoice\CoreBundle\Repository\ExpenseRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class ListExpenses
{
    public function __construct(
        private ExpenseRepository $expenseRepository,
    ) {
    }

    /**
     * @return array{expenses: list<\SolidInvoice\CoreBundle\Entity\Expense>, totalExpenses: string}
     */
    #[Template('@SolidInvoiceCore/Expense/list.html.twig')]
    public function __invoke(): array
    {
        $expenses = $this->expenseRepository->findAllOrdered();

        $total = BigDecimal::zero();

        foreach ($expenses as $expense) {
            $total = $total->plus(BigDecimal::of($expense->getAmount()));
        }

        return [
            'expenses' => $expenses,
            'totalExpenses' => (string) $total->toScale(2),
        ];
    }
}
