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

use InvalidArgumentException;
use SolidInvoice\CoreBundle\Entity\StockGrade;
use SolidInvoice\CoreBundle\Entity\StockModel;
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
 * Move units from one grade of an item to another.
 *
 * Stock booked in as Grade A that turns out on inspection to be Grade C has not
 * arrived and has not been sold - it has moved sideways, and the item's total
 * does not change. Without this the only way to record it would be two
 * unrelated corrections, which balance on paper but lose the fact that they
 * were the same event.
 */
#[IsGranted('ROLE_MANAGER')]
final class RegradeStock extends AbstractController
{
    public function __construct(
        private readonly StockModelRepository $stockModelRepository,
        private readonly StockLedger $ledger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('stock.regrade', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_stock_list');
        }

        $modelId = trim((string) $request->request->get('model'));
        $model = Ulid::isValid($modelId) ? $this->stockModelRepository->find(Ulid::fromString($modelId)) : null;

        if (! $model instanceof StockModel) {
            $this->addFlash('error', 'That stock item could not be found.');

            return $this->redirectToRoute('_stock_list');
        }

        $back = ['model' => $modelId];

        $from = $this->grade($model, (string) $request->request->get('from'));
        $to = $this->grade($model, (string) $request->request->get('to'));
        $quantity = trim((string) $request->request->get('quantity'));

        if (! $from instanceof StockGrade || ! $to instanceof StockGrade) {
            $this->addFlash('error', 'Choose which grade the units are moving from and which they are moving to.');

            return $this->redirectToRoute('_stock_movements', $back);
        }

        if (! is_numeric($quantity)) {
            $this->addFlash('error', 'Enter how many units are moving.');

            return $this->redirectToRoute('_stock_movements', $back);
        }

        $note = trim((string) $request->request->get('note'));

        try {
            $this->ledger->regrade($model, $from, $to, (int) $quantity, $note !== '' ? $note : null);
        } catch (InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('_stock_movements', $back);
        }

        $this->addFlash('success', sprintf(
            'Moved %d %s from %s to %s. The total for %s has not changed.',
            (int) $quantity,
            (int) $quantity === 1 ? 'unit' : 'units',
            $from->getGrade(),
            $to->getGrade(),
            $model->getName(),
        ));

        return $this->redirectToRoute('_stock_movements', $back);
    }

    /**
     * Resolve a grade from the item's own list, so an id from somewhere else on
     * the portal simply does not resolve.
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
