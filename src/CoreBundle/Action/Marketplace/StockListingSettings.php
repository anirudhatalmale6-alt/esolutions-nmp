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

namespace SolidInvoice\CoreBundle\Action\Marketplace;

use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Marketplace\MarketplaceManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function trim;

/**
 * "Stock Listing" page (System > Stock Listing): the business owner opts their
 * Tally stock in or out of the public Marketplace and sets the WhatsApp number
 * buyers are handed. When the toggle is off, the company's stock never appears in
 * a search; when no number is set, the Chat Now button simply does not show.
 */
#[IsGranted('ROLE_ADMIN')]
final class StockListingSettings extends AbstractController
{
    public function __construct(
        private readonly MarketplaceManager $marketplace,
        private readonly CompanySelector $companySelector,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $binaryCompanyId = $this->companySelector->getCompany()?->toBinary();

        if ($binaryCompanyId === null) {
            return $this->render('@SolidInvoiceCore/Marketplace/settings.html.twig', [
                'listed' => false, 'whatsapp' => '', 'country' => '', 'city' => '', 'countries' => $this->marketplace->countryChoices(),
            ]);
        }

        if ($request->isMethod('POST')) {
            if (! $this->isCsrfTokenValid('marketplace.settings', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Your session expired, please try again.');

                return $this->redirectToRoute('_marketplace_settings');
            }

            $listed = $request->request->getBoolean('listed');
            $whatsapp = trim((string) $request->request->get('whatsapp', ''));
            $country = trim((string) $request->request->get('country', ''));
            $city = trim((string) $request->request->get('city', ''));

            $this->marketplace->save($binaryCompanyId, $listed, $whatsapp, $country, $city);

            $this->addFlash('success', $listed
                ? 'Saved - your stock is now listed on the Marketplace.'
                : 'Saved - your stock is not listed on the Marketplace.');

            return $this->redirectToRoute('_marketplace_settings');
        }

        $settings = $this->marketplace->getForCompany($binaryCompanyId);

        return $this->render('@SolidInvoiceCore/Marketplace/settings.html.twig', [
            'listed' => $settings['listed'],
            'whatsapp' => $settings['whatsapp'],
            'country' => $settings['country'],
            'city' => $settings['city'],
            'countries' => $this->marketplace->countryChoices(),
        ]);
    }
}
