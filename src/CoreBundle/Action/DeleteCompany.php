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

use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Ulid;
use function trim;

final class DeleteCompany extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly CompanySelector $companySelector,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // Check if the CSRF token is valid
        $csrfToken = $request->request->get('_csrf_token');

        if (! $this->isCsrfTokenValid('delete_company', $csrfToken)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $companyId = $this->companySelector->getCompany();
        $company = $companyId instanceof Ulid ? $this->companyRepository->find($companyId) : null;

        if (! $company instanceof Company) {
            throw new BadRequestHttpException('No company is selected.');
        }

        // The page asks for the company name to be typed and only then enables
        // the button, but that is a courtesy in the browser, not a safeguard:
        // the form posts the typed name and until now nothing here looked at it,
        // so the same request sent without it deleted the business anyway. This
        // is the check that actually holds.
        if (trim((string) $request->request->get('company_name')) !== trim($company->getName())) {
            $this->addFlash('error', 'Nothing was deleted - the company name did not match.');

            return $this->redirectToRoute('_settings');
        }

        $this->companyRepository->deleteCompany($companyId);

        if ($request->hasSession()) {
            $session = $request->getSession();
            $session->remove('company');
        }

        $this->addFlash('success', 'Company deleted successfully.');

        return $this->redirectToRoute('_select_company');
    }
}
