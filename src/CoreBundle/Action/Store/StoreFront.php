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

namespace SolidInvoice\CoreBundle\Action\Store;

use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\StoreProduct;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Repository\StoreProductRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use function in_array;

/**
 * The public, no-login MobilesOnline storefront. Lists the curated store
 * products (a separate catalogue from the internal wholesale stock), with the
 * cart handled client-side and the finished order handed to WhatsApp for the
 * despatch team to process.
 */
final readonly class StoreFront
{
    /**
     * Despatch WhatsApp number in international format, digits only (no +, no
     * spaces), e.g. 9715XXXXXXXX. Set to the client's number once confirmed;
     * empty means the order text opens WhatsApp without a preset recipient.
     */
    private const WHATSAPP_NUMBER = '971585678669';

    /**
     * Public storefront brand. Deliberately separate from the company record
     * (which is "NMP MOBILES" for invoicing): the retail store trades under the
     * licensed name "Mobiles Online", so the customer never sees "NMP" here.
     */
    private const STORE_BRAND = 'Mobiles Online';

    public function __construct(
        private CompanyRepository $companyRepository,
        private StoreProductRepository $storeProductRepository,
    ) {
    }

    /**
     * @return array{company: Company, brand: string, products: list<StoreProduct>, makes: list<string>, whatsapp: string}
     */
    #[Template('@SolidInvoiceCore/Store/storefront.html.twig')]
    public function __invoke(): array
    {
        // Anonymous request: the company Doctrine filter adds no constraint, so
        // take the owning company from the products themselves (falling back to
        // the only company on the install) - same approach as the public stock
        // page, so the store shows the right business regardless of setup.
        $company = $this->companyRepository->findOneBy([]);

        if (! $company instanceof Company) {
            throw new NotFoundHttpException();
        }

        $products = $this->storeProductRepository->findForCompany($company);

        $makes = [];
        foreach ($products as $product) {
            $make = $product->getMake();
            if ($make !== '' && ! in_array($make, $makes, true)) {
                $makes[] = $make;
            }
        }

        return [
            'company' => $company,
            'brand' => self::STORE_BRAND,
            'products' => $products,
            'makes' => $makes,
            'whatsapp' => self::WHATSAPP_NUMBER,
        ];
    }
}
