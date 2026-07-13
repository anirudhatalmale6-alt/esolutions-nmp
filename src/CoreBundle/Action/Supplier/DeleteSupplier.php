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

use SolidInvoice\CoreBundle\Entity\Supplier;
use SolidInvoice\CoreBundle\Repository\SupplierRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DeleteSupplier extends AbstractController
{
    public function __construct(
        private readonly SupplierRepository $supplierRepository,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (! $this->isCsrfTokenValid('supplier.delete', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_suppliers_list');
        }

        if (Ulid::isValid($id)) {
            $supplier = $this->supplierRepository->find(Ulid::fromString($id));

            if ($supplier instanceof Supplier) {
                $this->supplierRepository->delete($supplier);
                $this->addFlash('success', 'Supplier deleted.');
            }
        }

        return $this->redirectToRoute('_suppliers_list');
    }
}
