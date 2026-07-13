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

use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Repository\StockModelRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public, no-login stock availability page shared with customers. Shows model,
 * grade and quantity only - never rates or values.
 */
final readonly class PublicStock
{
    public function __construct(
        private CompanyRepository $companyRepository,
        private StockModelRepository $stockModelRepository,
    ) {
    }

    /**
     * @return array{company: Company, models: list<\SolidInvoice\CoreBundle\Entity\StockModel>}
     */
    #[Template('@SolidInvoiceCore/Stock/public.html.twig')]
    public function __invoke(): array
    {
        // This is an anonymous request, so the company Doctrine filter adds no
        // constraint and this returns the stock as imported. The owning company
        // is taken from the stock itself, so the page shows the right business
        // regardless of how many companies exist on the install.
        $models = $this->stockModelRepository->findAllOrdered();

        $company = $models !== []
            ? $models[0]->getCompany()
            : $this->companyRepository->findOneBy([]);

        if (! $company instanceof Company) {
            throw new NotFoundHttpException();
        }

        return [
            'company' => $company,
            'models' => $models,
        ];
    }
}
