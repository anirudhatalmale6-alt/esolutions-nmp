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
use SolidInvoice\CoreBundle\Company\CompanySelector;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serves the active company's phone-model list that powers the "type the model on
 * line 1" suggestion box on invoice / quote line items. The list is company-owned
 * (seeded once from the built-in manufacturer catalogue, then editable from the
 * "Manage model list" page), so it is returned private and un-cached - it must
 * reflect the owner's latest edits immediately.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ModelCatalog
{
    public function __construct(
        private readonly ModelCatalogManager $catalog,
        private readonly CompanySelector $companySelector,
    ) {
    }

    public function __invoke(): Response
    {
        $binaryCompanyId = $this->companySelector->getCompany()?->toBinary();

        if ($binaryCompanyId === null) {
            return $this->json([]);
        }

        $this->catalog->ensureSeeded($binaryCompanyId);

        return $this->json($this->catalog->names($binaryCompanyId));
    }

    /**
     * @param list<string> $names
     */
    private function json(array $names): JsonResponse
    {
        $response = new JsonResponse($names, Response::HTTP_OK);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
