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
use SolidInvoice\CoreBundle\Repository\StockModelRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Public, no-login stock availability page shared with customers. Shows model,
 * grade and quantity only - never rates or values.
 */
final readonly class PublicStock
{
    public function __construct(
        private CompanyRepository $companyRepository,
        private StockModelRepository $stockModelRepository,
        private CompanySelector $companySelector,
    ) {
    }

    /**
     * @return array{company: Company, models: list<\SolidInvoice\CoreBundle\Entity\StockModel>}
     */
    #[Template('@SolidInvoiceCore/Stock/public.html.twig')]
    public function __invoke(string $token): array
    {
        if (! Ulid::isValid($token)) {
            throw new NotFoundHttpException();
        }

        $company = $this->companyRepository->find(Ulid::fromString($token));

        if (! $company instanceof Company) {
            throw new NotFoundHttpException();
        }

        // Align the request to this company (mirrors the public billing view)
        // so any company-scoped query resolves to the right tenant.
        $this->companySelector->switchCompany($company->getId());

        return [
            'company' => $company,
            'models' => $this->stockModelRepository->findForCompany($company),
        ];
    }
}
