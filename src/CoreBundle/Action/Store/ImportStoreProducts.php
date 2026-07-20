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

namespace SolidInvoice\CoreBundle\Action\Store;

use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Store\StoreProductImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;
use function in_array;
use function sprintf;
use function strtolower;
use function sys_get_temp_dir;
use function uniqid;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ImportStoreProducts extends AbstractController
{
    public function __construct(
        private readonly StoreProductImporter $importer,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleUpload($request);
        }

        return $this->render('@SolidInvoiceCore/Store/import.html.twig');
    }

    private function handleUpload(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('store.import', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try the upload again.');

            return $this->redirectToRoute('_store_import');
        }

        $file = $request->files->get('product_file');

        if (! $file instanceof UploadedFile) {
            $this->addFlash('error', 'Please choose a product file to upload.');

            return $this->redirectToRoute('_store_import');
        }

        $companyId = $this->companySelector->getCompany();
        $company = $companyId !== null ? $this->companyRepository->find($companyId) : null;

        if (! $company instanceof Company) {
            $this->addFlash('error', 'No active company selected.');

            return $this->redirectToRoute('_store_import');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['xls', 'xlsx'], true)) {
            $extension = 'xlsx';
        }

        $movedFile = null;

        try {
            $movedFile = $file->move(sys_get_temp_dir(), uniqid('store_import_', true) . '.' . $extension);
            $summary = $this->importer->import($movedFile->getPathname(), $company);
        } catch (Throwable $e) {
            $this->addFlash('error', sprintf('Could not read that file. Please upload the product Excel using the provided template. (%s)', $e->getMessage()));

            return $this->redirectToRoute('_store_import');
        } finally {
            if ($movedFile !== null && $movedFile->getRealPath() !== false) {
                @unlink($movedFile->getPathname());
            }
        }

        $this->addFlash('success', sprintf(
            'Store updated: %d product(s) added, %d updated. Now upload a photo for any new phones.',
            $summary['created'],
            $summary['updated'],
        ));

        return $this->redirectToRoute('_store_admin');
    }
}
