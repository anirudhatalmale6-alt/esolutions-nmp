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

namespace SolidInvoice\CoreBundle\Action\Supplier;

use SolidInvoice\CoreBundle\Repository\SupplierRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class ListSuppliers
{
    public function __construct(
        private SupplierRepository $supplierRepository,
    ) {
    }

    /**
     * @return array{suppliers: list<\SolidInvoice\CoreBundle\Entity\Supplier>}
     */
    #[Template('@SolidInvoiceCore/Supplier/list.html.twig')]
    public function __invoke(): array
    {
        return [
            'suppliers' => $this->supplierRepository->findAllOrdered(),
        ];
    }
}
