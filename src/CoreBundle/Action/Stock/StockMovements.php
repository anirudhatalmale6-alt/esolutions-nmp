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

use SolidInvoice\CoreBundle\Entity\StockModel;
use SolidInvoice\CoreBundle\Repository\StockModelRepository;
use SolidInvoice\CoreBundle\Repository\StockMovementRepository;
use SolidInvoice\CoreBundle\Stock\StockLedger;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/**
 * The stock history: every in and out, with the document behind it.
 *
 * The running quantity answers "how many do I have". This answers the question
 * that follows it - "why is it that number" - which is the one that decides
 * whether anybody trusts the first answer.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class StockMovements
{
    public function __construct(
        private StockMovementRepository $movementRepository,
        private StockModelRepository $stockModelRepository,
        private StockLedger $ledger,
    ) {
    }

    /**
     * @return array{movements: list<\SolidInvoice\CoreBundle\Entity\StockMovement>, model: ?StockModel, inSync: ?bool, expected: ?int, gradesAddUp: ?bool}
     */
    #[Template('@SolidInvoiceCore/Stock/movements.html.twig')]
    public function __invoke(Request $request): array
    {
        $model = $this->requestedModel($request);

        return [
            'movements' => $this->movementRepository->findRecent($model),
            'model' => $model,
            // Only meaningful for a single item: whether the running figure still
            // agrees with the history behind it.
            'inSync' => $model instanceof StockModel ? $this->ledger->isInSync($model) : null,
            'expected' => $model instanceof StockModel ? $this->movementRepository->netQuantityForModel($model) : null,
            // And whether its grades still add up to that figure, which is the
            // other way the two halves can drift apart.
            'gradesAddUp' => $model instanceof StockModel ? $this->ledger->gradesAddUp($model) : null,
        ];
    }

    private function requestedModel(Request $request): ?StockModel
    {
        $id = trim((string) $request->query->get('model', ''));

        if ($id === '' || ! Ulid::isValid($id)) {
            return null;
        }

        // Through the repository, so the company filter applies - another
        // business's item simply resolves to nothing.
        return $this->stockModelRepository->find(Ulid::fromString($id));
    }
}
