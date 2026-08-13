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
use SolidInvoice\CoreBundle\Marketplace\MarketplaceManager;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Repository\StockModelRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public, no-login stock availability page a business shares with its customers.
 * Shows model, grade and quantity only - never rates or values.
 *
 * Each business has its own address (/inventory/{slug}) and its own on/off
 * switch, so the page shows that business's stock and nothing else. The page
 * only exists while the switch is on: turning it off makes the link a 404,
 * which is what "stop sharing" has to mean.
 */
final readonly class PublicStock
{
    public function __construct(
        private CompanyRepository $companyRepository,
        private StockModelRepository $stockModelRepository,
        private MarketplaceManager $marketplace,
    ) {
    }

    /**
     * @return array{company: Company, models: list<\SolidInvoice\CoreBundle\Entity\StockModel>}
     */
    #[Template('@SolidInvoiceCore/Stock/public.html.twig')]
    public function __invoke(string $slug): array
    {
        $companyId = $this->marketplace->companyIdForSharedStock($slug);

        if ($companyId === null) {
            throw new NotFoundHttpException();
        }

        $company = $this->companyRepository->find($companyId);

        if (! $company instanceof Company) {
            throw new NotFoundHttpException();
        }

        return [
            'company' => $company,
            // Scoped to this company explicitly. This is an anonymous request, so
            // the usual company filter has no company to scope by and would hand
            // back every business's stock at once.
            'models' => $this->stockModelRepository->findForCompany($company),
        ];
    }
}
