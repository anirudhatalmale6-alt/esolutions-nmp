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

namespace SolidInvoice\CoreBundle\Action;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serves the phone-model master list that powers the "type the model on line 1"
 * suggestion box on the invoice / quote line items. The list is a static,
 * manufacturer-sourced catalogue (Apple, Samsung, Sony, Sharp Aquos, Kyocera,
 * Xiaomi, Oppo) kept as a JSON file in the bundle, so the owner always picks the
 * same spelling for a given model and the Sales-by-Model report groups cleanly.
 *
 * Returned as a long-cached JSON array of strings so the browser fetches it once
 * and reuses it across every invoice and quote page.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ModelCatalog
{
    private const CATALOG_FILE = __DIR__ . '/../Resources/data/phone_models.json';

    public function __invoke(): Response
    {
        $json = @file_get_contents(self::CATALOG_FILE);

        if ($json === false) {
            // Never break the page over the suggestion list - just serve empty.
            $json = '[]';
        }

        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setPublic();
        $response->setMaxAge(86400);
        $response->headers->addCacheControlDirective('immutable');

        return $response;
    }
}
