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

    /**
     * Registered business identity shown in the storefront footer and emitted
     * as structured data / social-share metadata. Displayed publicly to satisfy
     * the client's Tabby (BNPL) onboarding and corporate-tax registration, which
     * require a verifiable trade licence and customer-service contact on the
     * site. Static display strings - the retail store is a single legal entity.
     */
    private const STORE_LEGAL_NAME = 'Mobiles Online - Online Seller';

    private const STORE_LICENCE_NUMBER = '1596056';

    private const STORE_LICENCE_AUTHORITY = 'Dubai Department of Economy and Tourism';

    private const STORE_AREA = 'Deira';

    private const STORE_CITY = 'Dubai';

    private const STORE_COUNTRY = 'United Arab Emirates';

    /** Customer-service WhatsApp: human-readable + digits-only (for wa.me / tel:). */
    private const STORE_SUPPORT_PHONE_DISPLAY = '+971 58 585 8942';

    private const STORE_SUPPORT_PHONE = '971585858942';

    private const STORE_SUPPORT_EMAIL = 'mobilesonline.ae@gmail.com';

    /**
     * Canonical public address of the shop. The store is reachable on both the
     * branded retail domain and the invoicing host, so we pin canonical/OG URLs
     * to the branded domain to consolidate SEO there.
     */
    private const STORE_CANONICAL_URL = 'https://mobilesonline.ae/store';

    private const STORE_OG_IMAGE = 'https://mobilesonline.ae/mobilesonline-og.png';

    public function __construct(
        private CompanyRepository $companyRepository,
        private StoreProductRepository $storeProductRepository,
    ) {
    }

    /**
     * @return array{company: Company, brand: string, products: list<StoreProduct>, makes: list<string>, whatsapp: string, store: array<string, string>}
     */
    #[Template('@SolidInvoiceCore/Store/storefront.html.twig')]
    public function __invoke(): array
    {
        // Anonymous request: the company Doctrine filter adds no constraint, so
        // findAllOrdered() returns the store catalogue as imported. Take the
        // owning company from the products themselves (falling back to the only
        // company on the install) - exactly like the public stock page, so the
        // store shows the right business regardless of how many company rows
        // exist. NOTE: resolving the company first via findOneBy([]) then
        // filtering by it is WRONG here - findOneBy([]) can return a different
        // company than the one that owns the products, giving an empty store.
        $products = $this->storeProductRepository->findAllOrdered();

        $company = $products !== []
            ? $products[0]->getCompany()
            : $this->companyRepository->findOneBy([]);

        if (! $company instanceof Company) {
            throw new NotFoundHttpException();
        }

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
            'store' => [
                'legalName' => self::STORE_LEGAL_NAME,
                'licenceNumber' => self::STORE_LICENCE_NUMBER,
                'licenceAuthority' => self::STORE_LICENCE_AUTHORITY,
                'area' => self::STORE_AREA,
                'city' => self::STORE_CITY,
                'country' => self::STORE_COUNTRY,
                'supportPhoneDisplay' => self::STORE_SUPPORT_PHONE_DISPLAY,
                'supportPhone' => self::STORE_SUPPORT_PHONE,
                'supportEmail' => self::STORE_SUPPORT_EMAIL,
                'canonicalUrl' => self::STORE_CANONICAL_URL,
                'ogImage' => self::STORE_OG_IMAGE,
            ],
        ];
    }
}
