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

namespace SolidInvoice\CoreBundle\Action\Receivables;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Receivables\DebtorImporter;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;
use function array_filter;
use function count;
use function in_array;
use function is_array;
use function sprintf;
use function strtolower;
use function sys_get_temp_dir;
use function trim;
use function uniqid;

/**
 * Carries the old Tally debtor ledger over into B2B Network.
 *
 * Three steps, so nothing is written blind:
 *   1. upload the Tally "Sundry Debtors" Excel export;
 *   2. review every line - the loan / staff / expense accounts arrive unticked,
 *      and each row shows whether that customer already exists here;
 *   3. confirm, which writes the balance onto each chosen customer (creating the
 *      customer if they are new) and shows up on the Debtors report.
 *
 * The parsed rows travel through the preview as hidden fields rather than a
 * session or a temp file, so a half-finished import leaves nothing behind.
 */
#[IsGranted('ROLE_ADMIN')]
final class ImportDebtors extends AbstractController
{
    public function __construct(
        private readonly DebtorImporter $debtorImporter,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $request->isMethod('POST')) {
            return $this->render('@SolidInvoiceCore/Receivables/import.html.twig');
        }

        return match ($request->request->get('intent')) {
            'confirm' => $this->confirm($request),
            default => $this->preview($request),
        };
    }

    /**
     * Step 2 - read the sheet and show what would happen.
     */
    private function preview(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('debtors.import', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try the upload again.');

            return $this->redirectToRoute('_debtors_import');
        }

        $company = $this->activeCompany();

        if (! $company instanceof Company) {
            $this->addFlash('error', 'No active company selected.');

            return $this->redirectToRoute('_debtors_import');
        }

        $file = $request->files->get('debtor_file');

        if (! $file instanceof UploadedFile) {
            $this->addFlash('error', 'Please choose the Tally debtors file to upload.');

            return $this->redirectToRoute('_debtors_import');
        }

        // PhpSpreadsheet picks its reader from the file extension, but the
        // uploaded temp file has none - move it somewhere that keeps it.
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['xls', 'xlsx'], true)) {
            $extension = 'xlsx';
        }

        $movedFile = null;

        try {
            $movedFile = $file->move(sys_get_temp_dir(), uniqid('debtor_import_', true) . '.' . $extension);
            $parsed = $this->debtorImporter->parse($movedFile->getPathname(), $company, $this->asOf($request));
            $rows = $parsed['rows'];
            $asOf = $parsed['asOf'];
        } catch (Throwable $e) {
            $this->addFlash('error', sprintf('Could not read that file. Please upload the Tally Sundry Debtors Excel export. (%s)', $e->getMessage()));

            return $this->redirectToRoute('_debtors_import');
        } finally {
            if ($movedFile !== null) {
                @unlink($movedFile->getPathname());
            }
        }

        if ($rows === []) {
            $this->addFlash('error', 'No debtor lines were found in that file. Please check it is the Sundry Debtors group summary.');

            return $this->redirectToRoute('_debtors_import');
        }

        $owed = BigDecimal::zero();
        $owing = BigDecimal::zero();

        foreach ($rows as $row) {
            $balance = BigDecimal::of($row['balance']);

            if ($balance->isNegative()) {
                $owing = $owing->plus($balance->abs());
            } else {
                $owed = $owed->plus($balance);
            }
        }

        return $this->render('@SolidInvoiceCore/Receivables/preview.html.twig', [
            'rows' => $rows,
            'clients' => $this->debtorImporter->clients($company),
            'totalOwed' => (string) $owed->toScale(2),
            'totalOwing' => (string) $owing->toScale(2),
            'suggested' => count(array_filter($rows, static fn (array $row): bool => $row['isCustomer'])),
            'matched' => count(array_filter($rows, static fn (array $row): bool => $row['matchId'] !== null)),
            'basis' => $this->basis($request),
            'asOf' => $asOf,
            // Lets the preview re-do the unwind sum on screen when a different
            // customer is picked, so the figures shown always match what will
            // actually be saved.
            'positionByClient' => $this->debtorImporter->positionAsOf($company, new DateTimeImmutable($asOf)),
        ]);
    }

    /**
     * Step 3 - write the ticked rows onto the customers.
     */
    private function confirm(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('debtors.import', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please start the import again.');

            return $this->redirectToRoute('_debtors_import');
        }

        $company = $this->activeCompany();

        if (! $company instanceof Company) {
            $this->addFlash('error', 'No active company selected.');

            return $this->redirectToRoute('_debtors_import');
        }

        $names = $request->request->all('name');
        $balances = $request->request->all('balance');
        $selected = $request->request->all('import');
        $matches = $request->request->all('match');

        if (! is_array($names) || $names === []) {
            $this->addFlash('error', 'Nothing to import - please upload the file again.');

            return $this->redirectToRoute('_debtors_import');
        }

        $rows = [];

        foreach ($names as $index => $name) {
            // Only rows whose checkbox came back are imported; the checkbox
            // value is the row index, so unticked rows simply never appear.
            if (! isset($selected[$index])) {
                continue;
            }

            $name = trim((string) $name);
            $balance = trim((string) ($balances[$index] ?? '0'));

            if ($name === '') {
                continue;
            }

            // Empty match = "create a new customer under the Tally name"; a hex
            // id links the line to a customer already on the system, whatever
            // that customer happens to be called here.
            $rows[] = [
                'name' => $name,
                'balance' => $balance,
                'clientId' => trim((string) ($matches[$index] ?? '')),
            ];
        }

        if ($rows === []) {
            $this->addFlash('error', 'No lines were ticked, so nothing was imported.');

            return $this->redirectToRoute('_debtors_import');
        }

        $basis = $this->basis($request);

        try {
            $summary = $this->debtorImporter->import($rows, $company, $basis, $this->asOf($request));
        } catch (Throwable $e) {
            $this->addFlash('error', sprintf('Could not save the balances: %s', $e->getMessage()));

            return $this->redirectToRoute('_debtors_import');
        }

        $message = sprintf(
            'Opening balances carried over: %d existing customer(s) updated, %d new customer(s) created, net %s.',
            $summary['updated'],
            $summary['created'],
            $summary['total'],
        );

        if ($basis === 'total' && $summary['adjusted'] > 0) {
            $message .= sprintf(
                ' On %d customer(s) the unpaid B2B invoices were taken off the sheet figure, so nothing is counted twice.',
                $summary['adjusted'],
            );
        }

        $this->addFlash('success', $message);

        return $this->redirectToRoute('_debtors_report');
    }

    /**
     * What the figures in the uploaded sheet mean. 'total' (the default) treats
     * them as the customer's full current balance, invoices raised here
     * included; 'old_only' treats them as pre-B2B debt to carry over as-is.
     */
    private function basis(Request $request): string
    {
        return $request->request->get('basis') === 'old_only' ? 'old_only' : 'total';
    }

    /**
     * The date the sheet's balances are true as at. Read from the Tally period
     * heading on upload, then carried through the preview so the confirm step
     * unwinds against exactly the same position the preview showed.
     */
    private function asOf(Request $request): ?DateTimeImmutable
    {
        $value = trim((string) $request->request->get('as_of', ''));

        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function activeCompany(): ?Company
    {
        $companyId = $this->companySelector->getCompany();

        return $companyId !== null ? $this->companyRepository->find($companyId) : null;
    }
}
