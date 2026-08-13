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

use Brick\Math\BigDecimal;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Marketplace\MarketplaceManager;
use SolidInvoice\CoreBundle\Repository\StockModelRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class ListStock
{
    public function __construct(
        private StockModelRepository $stockModelRepository,
        private UrlGeneratorInterface $urlGenerator,
        private CompanySelector $companySelector,
        private MarketplaceManager $marketplace,
    ) {
    }

    /**
     * @return array{models: list<\SolidInvoice\CoreBundle\Entity\StockModel>, totalQuantity: int, totalValue: string, shareUrl: string, lastImported: ?\DateTimeInterface}
     */
    #[Template('@SolidInvoiceCore/Stock/list.html.twig')]
    public function __invoke(): array
    {
        $models = $this->stockModelRepository->findAllOrdered();

        $totalQuantity = 0;
        $totalValue = BigDecimal::zero();
        // The whole stock is cleared and re-imported on each Tally upload, so every
        // row shares the same timestamp; the newest one is when stock was last updated.
        $lastImported = null;

        foreach ($models as $model) {
            $totalQuantity += $model->getQuantity();
            $totalValue = $totalValue->plus(BigDecimal::of($model->getValue()));

            $stamp = $model->getUpdated() ?? $model->getCreated();
            if ($stamp !== null && ($lastImported === null || $stamp > $lastImported)) {
                $lastImported = $stamp;
            }
        }

        return [
            'models' => $models,
            'totalQuantity' => $totalQuantity,
            'totalValue' => (string) $totalValue->toScale(2),
            'shareUrl' => $this->shareUrl(),
            'lastImported' => $lastImported,
        ];
    }

    /**
     * This business's own public stock link, or '' when it has not published one.
     *
     * Read from its own settings rather than generated blindly: on a portal with
     * several businesses, a fixed link would send every one of them to somebody
     * else's stock page.
     */
    private function shareUrl(): string
    {
        $companyId = $this->companySelector->getCompany();

        if ($companyId === null) {
            return '';
        }

        $settings = $this->marketplace->getForCompany($companyId->toBinary());

        if (! $settings['shareStock'] || $settings['shareSlug'] === '') {
            return '';
        }

        return $this->urlGenerator->generate(
            '_stock_public_member',
            ['slug' => $settings['shareSlug']],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }
}
