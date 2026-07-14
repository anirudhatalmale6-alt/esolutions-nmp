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
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\Expense;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Repository\ExpenseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use Throwable;
use function is_numeric;
use function trim;

/**
 * Records a new payout / expense (rent, salary, utilities, etc) or edits an
 * existing one. Money-out only, feeds the daily ledger.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ManageExpense extends AbstractController
{
    /**
     * Suggested categories shown in the form (free text - the user can type
     * their own too).
     *
     * @var list<string>
     */
    private const array CATEGORIES = [
        'Rent',
        'Salaries',
        'Electricity / DEWA',
        'Water',
        'Internet / Phone',
        'Transport',
        'Bank charges',
        'Office supplies',
        'Repairs / Maintenance',
        'Marketing',
        'Government / Fees',
        'Miscellaneous',
    ];

    public function __construct(
        private readonly ExpenseRepository $expenseRepository,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    public function __invoke(Request $request, ?string $id = null): Response
    {
        $expense = null;

        if ($id !== null) {
            if (! Ulid::isValid($id)) {
                throw $this->createNotFoundException();
            }

            $expense = $this->expenseRepository->find(Ulid::fromString($id));

            if (! $expense instanceof Expense) {
                throw $this->createNotFoundException();
            }
        }

        if ($request->isMethod('POST')) {
            return $this->save($request, $expense);
        }

        return $this->renderForm($expense, $this->dataFromExpense($expense));
    }

    private function save(Request $request, ?Expense $expense): Response
    {
        if (! $this->isCsrfTokenValid('expense.save', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirect($request->getUri());
        }

        $data = [
            'category' => trim((string) $request->request->get('category')),
            'payee' => $this->nullify($request->request->get('payee')),
            'expense_date' => trim((string) $request->request->get('expense_date')),
            'amount' => trim((string) $request->request->get('amount')),
            'description' => $this->nullify($request->request->get('description')),
        ];

        if ($data['category'] === '') {
            $this->addFlash('error', 'Please choose or type a category.');

            return $this->renderForm($expense, $data);
        }

        if ($data['amount'] === '' || ! is_numeric($data['amount']) || BigDecimal::of($data['amount'])->isNegativeOrZero()) {
            $this->addFlash('error', 'Please enter an amount greater than zero.');

            return $this->renderForm($expense, $data);
        }

        try {
            $expenseDate = $data['expense_date'] !== ''
                ? new DateTimeImmutable($data['expense_date'])
                : new DateTimeImmutable('today');
        } catch (Throwable) {
            $this->addFlash('error', 'Please enter a valid date.');

            return $this->renderForm($expense, $data);
        }

        $amount = BigDecimal::of($data['amount'])->toScale(2, RoundingMode::HalfUp);

        if ($expense === null) {
            $companyId = $this->companySelector->getCompany();
            $company = $companyId !== null ? $this->companyRepository->find($companyId) : null;

            if (! $company instanceof Company) {
                $this->addFlash('error', 'No active company selected.');

                return $this->redirectToRoute('_expenses_list');
            }

            $expense = new Expense();
            $expense->setCompany($company);
        }

        $expense->setCategory($data['category'])
            ->setPayee($data['payee'])
            ->setExpenseDate($expenseDate)
            ->setAmount((string) $amount)
            ->setDescription($data['description']);

        $this->expenseRepository->save($expense);

        $this->addFlash('success', 'Expense saved.');

        return $this->redirectToRoute('_expenses_list');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderForm(?Expense $expense, array $data): Response
    {
        return $this->render('@SolidInvoiceCore/Expense/form.html.twig', [
            'expense' => $expense,
            'data' => $data,
            'categories' => self::CATEGORIES,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dataFromExpense(?Expense $expense): array
    {
        if (! $expense instanceof Expense) {
            return [
                'category' => '',
                'payee' => null,
                'expense_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
                'amount' => '',
                'description' => null,
            ];
        }

        return [
            'category' => $expense->getCategory(),
            'payee' => $expense->getPayee(),
            'expense_date' => $expense->getExpenseDate()?->format('Y-m-d') ?? '',
            'amount' => $expense->getAmount(),
            'description' => $expense->getDescription(),
        ];
    }

    private function nullify(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
