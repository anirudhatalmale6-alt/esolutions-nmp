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

use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\Supplier;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Repository\SupplierRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use function trim;

/**
 * Creates a new supplier or edits an existing one (same form for both).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ManageSupplier extends AbstractController
{
    public function __construct(
        private readonly SupplierRepository $supplierRepository,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    public function __invoke(Request $request, ?string $id = null): Response
    {
        $supplier = null;

        if ($id !== null) {
            if (! Ulid::isValid($id)) {
                throw $this->createNotFoundException();
            }

            $supplier = $this->supplierRepository->find(Ulid::fromString($id));

            if (! $supplier instanceof Supplier) {
                throw $this->createNotFoundException();
            }
        }

        if ($request->isMethod('POST')) {
            return $this->save($request, $supplier);
        }

        return $this->render('@SolidInvoiceCore/Supplier/form.html.twig', [
            'supplier' => $supplier,
            'data' => $this->dataFromSupplier($supplier),
        ]);
    }

    private function save(Request $request, ?Supplier $supplier): Response
    {
        if (! $this->isCsrfTokenValid('supplier.save', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirect($request->getUri());
        }

        $data = [
            'name' => trim((string) $request->request->get('name')),
            'contact_person' => $this->nullify($request->request->get('contact_person')),
            'email' => $this->nullify($request->request->get('email')),
            'phone' => $this->nullify($request->request->get('phone')),
            'tax_id' => $this->nullify($request->request->get('tax_id')),
            'address' => $this->nullify($request->request->get('address')),
            'notes' => $this->nullify($request->request->get('notes')),
        ];

        if ($data['name'] === '') {
            $this->addFlash('error', 'Supplier name is required.');

            return $this->render('@SolidInvoiceCore/Supplier/form.html.twig', [
                'supplier' => $supplier,
                'data' => $data,
            ]);
        }

        if ($supplier === null) {
            $companyId = $this->companySelector->getCompany();
            $company = $companyId !== null ? $this->companyRepository->find($companyId) : null;

            if (! $company instanceof Company) {
                $this->addFlash('error', 'No active company selected.');

                return $this->redirectToRoute('_suppliers_list');
            }

            $supplier = new Supplier();
            $supplier->setCompany($company);
        }

        $supplier->setName($data['name'])
            ->setContactPerson($data['contact_person'])
            ->setEmail($data['email'])
            ->setPhone($data['phone'])
            ->setTaxId($data['tax_id'])
            ->setAddress($data['address'])
            ->setNotes($data['notes']);

        $this->supplierRepository->save($supplier);

        $this->addFlash('success', 'Supplier saved.');

        return $this->redirectToRoute('_suppliers_list');
    }

    /**
     * @return array<string, string|null>
     */
    private function dataFromSupplier(?Supplier $supplier): array
    {
        if (! $supplier instanceof Supplier) {
            return [
                'name' => '',
                'contact_person' => null,
                'email' => null,
                'phone' => null,
                'tax_id' => null,
                'address' => null,
                'notes' => null,
            ];
        }

        return [
            'name' => $supplier->getName(),
            'contact_person' => $supplier->getContactPerson(),
            'email' => $supplier->getEmail(),
            'phone' => $supplier->getPhone(),
            'tax_id' => $supplier->getTaxId(),
            'address' => $supplier->getAddress(),
            'notes' => $supplier->getNotes(),
        ];
    }

    private function nullify(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
