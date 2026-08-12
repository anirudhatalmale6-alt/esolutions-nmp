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

use SolidInvoice\CoreBundle\Repository\StockModelRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serves this company's OWN stock list to the model picker on invoice and
 * purchase lines: id, name and quantity in hand.
 *
 * Distinct from {@see \SolidInvoice\CoreBundle\Action\ModelCatalog}, which
 * serves the portal-wide master list of model names shared by every vendor.
 * That one keeps spelling consistent; this one is what the vendor actually
 * holds, and picking from it is what links a line to a stock item so the
 * quantity can move.
 *
 * Private and un-cached: quantities change with every invoice, so a stale list
 * would show stock that has already gone out.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class StockPicker
{
    public function __construct(
        private readonly StockModelRepository $stockModelRepository,
    ) {
    }

    public function __invoke(): Response
    {
        $response = new JsonResponse($this->stockModelRepository->pickerList(), Response::HTTP_OK);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
