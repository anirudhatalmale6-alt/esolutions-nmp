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
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin overview of the IMEI unlock codes: how many are on file, a small recent
 * sample, the public lookup link to share, and the upload / clear controls.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class ListUnlockCodes
{
    public function __construct(
        private UnlockCodeRepository $unlockCodeRepository,
        private CompanySelector $companySelector,
        private CompanyRepository $companyRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array{total: int, recent: list<\SolidInvoice\CoreBundle\Entity\UnlockCode>, publicUrl: string}
     */
    #[Template('@SolidInvoiceCore/Unlock/list.html.twig')]
    public function __invoke(): array
    {
        $companyId = $this->companySelector->getCompany();
        $company = $companyId !== null ? $this->companyRepository->find($companyId) : null;

        $total = $company instanceof Company
            ? $this->unlockCodeRepository->countForCompany($company)
            : 0;

        return [
            'total' => $total,
            'recent' => $this->unlockCodeRepository->findRecent(),
            'publicUrl' => $this->urlGenerator->generate('_unlock_public', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }
}
