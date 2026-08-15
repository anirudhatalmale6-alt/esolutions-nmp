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

use SolidInvoice\CoreBundle\Entity\StockGrade;
use SolidInvoice\CoreBundle\Entity\StockModel;
use SolidInvoice\CoreBundle\Entity\StockMovement;
use SolidInvoice\CoreBundle\Repository\StockModelRepository;
use SolidInvoice\CoreBundle\Stock\StockLedger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use function is_numeric;
use function sprintf;
use function trim;

/**
 * A stock take: the user says how many are actually on the shelf, and the
 * difference is recorded as a correction.
 *
 * Deliberately phrased as a count rather than an adjustment. Asking "how many
 * are there" is a question somebody standing in the warehouse can answer;
 * asking "how many should I add or take away" is a sum they have to do first,
 * and get wrong.
 */
#[IsGranted('ROLE_MANAGER')]
final class AdjustStock extends AbstractController
{
    public function __construct(
        private readonly StockModelRepository $stockModelRepository,
        private readonly StockLedger $ledger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('stock.adjust', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_stock_list');
        }

        $id = trim((string) $request->request->get('model'));
        $model = Ulid::isValid($id) ? $this->stockModelRepository->find(Ulid::fromString($id)) : null;

        if (! $model instanceof StockModel) {
            $this->addFlash('error', 'That stock item could not be found.');

            return $this->redirectToRoute('_stock_list');
        }

        $counted = trim((string) $request->request->get('counted'));

        if (! is_numeric($counted)) {
            $this->addFlash('error', 'Please enter how many units you counted.');

            return $this->redirectToRoute('_stock_movements', ['model' => $id]);
        }

        // Counting happens per grade where the item has them, because that is
        // what is actually stacked on the shelf.
        $grade = $this->grade($model, (string) $request->request->get('grade'));

        if ($grade === null && ! $model->getGrades()->isEmpty()) {
            $this->addFlash('error', 'Choose which grade you counted.');

            return $this->redirectToRoute('_stock_movements', ['model' => $id]);
        }

        $note = trim((string) $request->request->get('note'));
        $what = $grade instanceof StockGrade
            ? sprintf('%s (%s)', $model->getName(), $grade->getGrade())
            : $model->getName();
        $before = $grade instanceof StockGrade ? $grade->getQuantity() : $model->getQuantity();
        $movement = $this->ledger->setCountedQuantity($model, (int) $counted, $note !== '' ? $note : null, $grade);

        if (! $movement instanceof StockMovement) {
            $this->addFlash('info', sprintf('%s was already showing %d - nothing to correct.', $what, $before));

            return $this->redirectToRoute('_stock_movements', ['model' => $id]);
        }

        $this->addFlash('success', sprintf(
            '%s corrected from %d to %d (%s%d).',
            $what,
            $before,
            $grade instanceof StockGrade ? $grade->getQuantity() : $model->getQuantity(),
            $movement->getQuantity() > 0 ? '+' : '',
            $movement->getQuantity(),
        ));

        return $this->redirectToRoute('_stock_movements', ['model' => $id]);
    }

    /**
     * Resolve a grade from the item's own list, so an id from elsewhere on the
     * portal simply does not resolve.
     */
    private function grade(StockModel $model, string $id): ?StockGrade
    {
        $id = trim($id);

        if ($id === '' || ! Ulid::isValid($id)) {
            return null;
        }

        foreach ($model->getGrades() as $grade) {
            if ((string) $grade->getId() === $id) {
                return $grade;
            }
        }

        return null;
    }
}
