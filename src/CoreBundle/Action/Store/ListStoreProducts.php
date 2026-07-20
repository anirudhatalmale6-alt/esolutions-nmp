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

use SolidInvoice\CoreBundle\Repository\StoreProductRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class ListStoreProducts
{
    public function __construct(
        private StoreProductRepository $storeProductRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array{products: list<\SolidInvoice\CoreBundle\Entity\StoreProduct>, storeUrl: string, csrfIntent: string}
     */
    #[Template('@SolidInvoiceCore/Store/admin_list.html.twig')]
    public function __invoke(): array
    {
        $products = $this->storeProductRepository->findAllOrdered();

        return [
            'products' => $products,
            'storeUrl' => $this->urlGenerator->generate('_store_front', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'csrfIntent' => 'store.product',
        ];
    }
}
