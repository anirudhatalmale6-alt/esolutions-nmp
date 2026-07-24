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

namespace SolidInvoice\CoreBundle\Action\Unlock;

use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Unlock\UnlockCodeImporter;
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
final class ImportUnlockCodes extends AbstractController
{
    public function __construct(
        private readonly UnlockCodeImporter $unlockCodeImporter,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleUpload($request);
        }

        return $this->render('@SolidInvoiceCore/Unlock/import.html.twig');
    }

    private function handleUpload(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('unlock.import', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try the upload again.');

            return $this->redirectToRoute('_unlock_import');
        }

        $file = $request->files->get('unlock_file');

        if (! $file instanceof UploadedFile) {
            $this->addFlash('error', 'Please choose an unlock-codes file to upload.');

            return $this->redirectToRoute('_unlock_import');
        }

        $companyId = $this->companySelector->getCompany();
        $company = $companyId !== null ? $this->companyRepository->find($companyId) : null;

        if (! $company instanceof Company) {
            $this->addFlash('error', 'No active company selected.');

            return $this->redirectToRoute('_unlock_import');
        }

        // PhpSpreadsheet picks its reader from the file extension, but the
        // uploaded temp file has none, so move it to a path that keeps the
        // original extension before reading.
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['xls', 'xlsx'], true)) {
            $extension = 'xlsx';
        }

        $movedFile = null;

        try {
            $movedFile = $file->move(sys_get_temp_dir(), uniqid('unlock_import_', true) . '.' . $extension);
            $summary = $this->unlockCodeImporter->import($movedFile->getPathname(), $company);
        } catch (Throwable $e) {
            $this->addFlash('error', sprintf('Could not read that file. Please upload the supplier unlock-codes Excel file. (%s)', $e->getMessage()));

            return $this->redirectToRoute('_unlock_import');
        } finally {
            if ($movedFile !== null && $movedFile->getRealPath() !== false) {
                @unlink($movedFile->getPathname());
            }
        }

        if ($summary['added'] === 0 && $summary['updated'] === 0 && $summary['removed'] === 0) {
            $this->addFlash('error', 'No IMEI codes were found in that file. Please check it has a column of IMEI numbers with the code next to it.');

            return $this->redirectToRoute('_unlock_import');
        }

        $message = sprintf(
            'Unlock codes updated: %d new, %d updated (%d IMEIs read from the file).',
            $summary['added'],
            $summary['updated'],
            $summary['total'],
        );

        if ($summary['removed'] > 0) {
            $message .= sprintf(' %d stale entr%s with no valid code removed.', $summary['removed'], $summary['removed'] === 1 ? 'y' : 'ies');
        }

        $this->addFlash('success', $message);

        return $this->redirectToRoute('_unlock_list');
    }
}
