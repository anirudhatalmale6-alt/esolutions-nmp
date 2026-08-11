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

use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Receivables\DebtorImporter;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * Undo a carried-over ledger import by clearing every customer's opening
 * balance. The debtors report falls back to invoices and payments alone, which
 * is where it started, so a mis-run import can simply be redone.
 *
 * Only the opening balance is touched - no customer, invoice or payment is
 * changed or deleted.
 *
 * Restricted to admins, and guarded by a typed confirmation, because it rewrites
 * every customer's carried-over balance in one go - not something a member of
 * staff should be able to trigger by mis-clicking.
 */
#[IsGranted('ROLE_ADMIN')]
final class ResetDebtors extends AbstractController
{
    public function __construct(
        private readonly DebtorImporter $debtorImporter,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('debtors.reset', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_debtors_report');
        }

        // Typing the word is the real guard - a confirm dialog alone is too easy
        // to click through on a screen full of live balances.
        if (strtoupper(trim((string) $request->request->get('confirm'))) !== 'CLEAR') {
            $this->addFlash('error', 'Nothing was cleared. Type CLEAR in the box to confirm.');

            return $this->redirectToRoute('_debtors_report');
        }

        $companyId = $this->companySelector->getCompany();
        $company = $companyId !== null ? $this->companyRepository->find($companyId) : null;

        if (! $company instanceof Company) {
            $this->addFlash('error', 'No active company selected.');

            return $this->redirectToRoute('_debtors_report');
        }

        $cleared = $this->debtorImporter->reset($company);

        $this->addFlash('success', sprintf(
            'Cleared the carried-over opening balance on %d customer(s). Invoices and payments are untouched - you can import the ledger again.',
            $cleared,
        ));

        return $this->redirectToRoute('_debtors_report');
    }
}
