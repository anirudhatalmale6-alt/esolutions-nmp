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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function count;
use function implode;

/**
 * "Phone models" page: the portal-wide shared model list that feeds the line-item
 * model suggestion box for every vendor. It is a single master list, so keeping it
 * tidy keeps the Sales-by-Model report accurate for everyone. Every admin can view
 * it, but only the Super User (platform owner) can edit or delete it - business
 * admins see it read-only so they cannot change or remove the shared list.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ModelCatalogManage extends AbstractController
{
    public function __construct(
        private readonly ModelCatalogManager $catalog,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $canEdit = $this->isGranted('ROLE_SUPER_ADMIN');

        if ($request->isMethod('POST')) {
            return $this->handleSave($request, $canEdit);
        }

        $this->catalog->ensureSharedSeeded();
        $names = $this->catalog->sharedNames();

        return $this->render('@SolidInvoiceCore/Catalog/manage.html.twig', [
            'models' => implode("\n", $names),
            'count' => count($names),
            'canEdit' => $canEdit,
        ]);
    }

    private function handleSave(Request $request, bool $canEdit): Response
    {
        if (! $canEdit) {
            $this->addFlash('error', 'Only the Super User can change the shared model list.');

            return $this->redirectToRoute('_model_catalog_manage');
        }

        if (! $this->isCsrfTokenValid('model.catalog', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_model_catalog_manage');
        }

        // "Restore default list" puts the full built-in manufacturer catalogue back.
        if ($request->request->get('action') === 'reset') {
            $saved = $this->catalog->replaceShared($this->catalog->defaults());
            $this->addFlash('success', sprintf('Restored the full model list (%d models).', $saved));

            return $this->redirectToRoute('_model_catalog_manage');
        }

        $names = $this->catalog->parse((string) $request->request->get('models', ''));

        if ($names === []) {
            $this->addFlash('error', 'The list was empty, so nothing was changed. Add at least one model, or use "Restore default list".');

            return $this->redirectToRoute('_model_catalog_manage');
        }

        $saved = $this->catalog->replaceShared($names);
        $this->addFlash('success', sprintf('Saved the shared model list - %d models. Every vendor now sees these as suggestions when they type a model on an invoice.', $saved));

        return $this->redirectToRoute('_model_catalog_manage');
    }
}
