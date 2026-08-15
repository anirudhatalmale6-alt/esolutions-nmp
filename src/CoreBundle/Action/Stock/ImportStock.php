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

use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Stock\StockAlreadyLiveException;
use SolidInvoice\CoreBundle\Stock\StockImporter;
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
final class ImportStock extends AbstractController
{
    public function __construct(
        private readonly StockImporter $stockImporter,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleUpload($request);
        }

        return $this->render('@SolidInvoiceCore/Stock/import.html.twig');
    }

    private function handleUpload(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('stock.import', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try the upload again.');

            return $this->redirectToRoute('_stock_import');
        }

        $file = $request->files->get('stock_file');

        if (! $file instanceof UploadedFile) {
            $this->addFlash('error', 'Please choose a stock file to upload.');

            return $this->redirectToRoute('_stock_import');
        }

        $companyId = $this->companySelector->getCompany();
        $company = $companyId !== null ? $this->companyRepository->find($companyId) : null;

        if (! $company instanceof Company) {
            $this->addFlash('error', 'No active company selected.');

            return $this->redirectToRoute('_stock_import');
        }

        // PhpSpreadsheet chooses its reader from the file extension, but the
        // uploaded temp file has none, so move it to a path that keeps the
        // original extension before reading.
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['xls', 'xlsx'], true)) {
            $extension = 'xlsx';
        }

        // Setting the opening figure again throws away everything the system has
        // counted since, so it has to be asked for deliberately.
        $force = $request->request->getBoolean('replace_live_stock');

        $movedFile = null;

        try {
            $movedFile = $file->move(sys_get_temp_dir(), uniqid('stock_import_', true) . '.' . $extension);
            $summary = $this->stockImporter->import($movedFile->getPathname(), $company, $force);
        } catch (StockAlreadyLiveException $e) {
            $this->addFlash('error', sprintf(
                '%s If you really want to start again from the sheet, tick "Replace the live count" and upload it again.',
                $e->getMessage(),
            ));

            return $this->redirectToRoute('_stock_import');
        } catch (Throwable $e) {
            $this->addFlash('error', sprintf('Could not read that file. Please upload the Tally stock summary Excel export. (%s)', $e->getMessage()));

            return $this->redirectToRoute('_stock_import');
        } finally {
            if ($movedFile !== null && $movedFile->getRealPath() !== false) {
                @unlink($movedFile->getPathname());
            }
        }

        $this->addFlash('success', sprintf(
            'Opening stock set from the sheet: %d item(s) read (%d new, %d updated), %d grade rows, %d units in hand.%s',
            $summary['models'],
            $summary['created'],
            $summary['updated'],
            $summary['grades'],
            $summary['quantity'],
            $summary['cleared'] > 0
                ? sprintf(' %d item(s) not on the sheet were taken to zero.', $summary['cleared'])
                : '',
        ));

        return $this->redirectToRoute('_stock_list');
    }
}
