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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function count;
use function implode;

/**
 * "Manage model list" page: the owner sees the company's phone-model list, one
 * per line, edits or pastes a fresh list, and saves. This is what feeds the model
 * suggestion box on invoice / quote line items, so keeping it tidy keeps the
 * Sales-by-Model report accurate. Replaces the old merge tool with something an
 * end customer understands at a glance.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ModelCatalogManage extends AbstractController
{
    public function __construct(
        private readonly ModelCatalogManager $catalog,
        private readonly CompanySelector $companySelector,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $binaryCompanyId = $this->companySelector->getCompany()?->toBinary();

        if ($binaryCompanyId === null) {
            return $this->render('@SolidInvoiceCore/Catalog/manage.html.twig', ['models' => '', 'count' => 0]);
        }

        if ($request->isMethod('POST')) {
            return $this->handleSave($request, $binaryCompanyId);
        }

        $this->catalog->ensureSeeded($binaryCompanyId);
        $names = $this->catalog->names($binaryCompanyId);

        return $this->render('@SolidInvoiceCore/Catalog/manage.html.twig', [
            'models' => implode("\n", $names),
            'count' => count($names),
        ]);
    }

    private function handleSave(Request $request, string $binaryCompanyId): Response
    {
        if (! $this->isCsrfTokenValid('model.catalog', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_model_catalog_manage');
        }

        // "Restore default list" puts the full built-in manufacturer catalogue back.
        if ($request->request->get('action') === 'reset') {
            $saved = $this->catalog->replace($binaryCompanyId, $this->catalog->defaults());
            $this->addFlash('success', sprintf('Restored the full model list (%d models).', $saved));

            return $this->redirectToRoute('_model_catalog_manage');
        }

        $names = $this->catalog->parse((string) $request->request->get('models', ''));

        if ($names === []) {
            $this->addFlash('error', 'The list was empty, so nothing was changed. Add at least one model, or use "Restore default list".');

            return $this->redirectToRoute('_model_catalog_manage');
        }

        $saved = $this->catalog->replace($binaryCompanyId, $names);
        $this->addFlash('success', sprintf('Saved your model list - %d models. These now show as suggestions when you type a model on an invoice.', $saved));

        return $this->redirectToRoute('_model_catalog_manage');
    }
}
