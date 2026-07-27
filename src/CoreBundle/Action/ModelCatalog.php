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

use SolidInvoice\CoreBundle\Catalog\ModelCatalogManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serves the portal-wide shared phone-model list that powers the "type the model
 * on line 1" suggestion box on invoice / quote line items. The list is a single
 * master list shared by every vendor (curated by the platform owner), so it is
 * the same for everyone. Returned private and un-cached so an owner edit shows
 * immediately.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ModelCatalog
{
    public function __construct(
        private readonly ModelCatalogManager $catalog,
    ) {
    }

    public function __invoke(): Response
    {
        $this->catalog->ensureSharedSeeded();

        $response = new JsonResponse($this->catalog->sharedNames(), Response::HTTP_OK);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
