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
use SolidInvoice\CoreBundle\Repository\UnlockCodeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function sprintf;

/**
 * Wipe every unlock code for the current company - a deliberate full reset,
 * separate from the additive import.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ClearUnlockCodes extends AbstractController
{
    public function __construct(
        private readonly UnlockCodeRepository $unlockCodeRepository,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('unlock.clear', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_unlock_list');
        }

        $companyId = $this->companySelector->getCompany();
        $company = $companyId !== null ? $this->companyRepository->find($companyId) : null;

        if (! $company instanceof Company) {
            $this->addFlash('error', 'No active company selected.');

            return $this->redirectToRoute('_unlock_list');
        }

        $removed = $this->unlockCodeRepository->deleteForCompany($company);

        $this->addFlash('success', sprintf('Cleared %d unlock code(s).', $removed));

        return $this->redirectToRoute('_unlock_list');
    }
}
