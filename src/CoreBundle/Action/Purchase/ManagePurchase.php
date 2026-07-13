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

namespace SolidInvoice\CoreBundle\Action\Purchase;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\Purchase;
use SolidInvoice\CoreBundle\Entity\Supplier;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Repository\PurchaseRepository;
use SolidInvoice\CoreBundle\Repository\SupplierRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use Throwable;
use function is_numeric;
use function trim;

/**
 * Creates a new purchase (supplier bill) or edits an existing one.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ManagePurchase extends AbstractController
{
    public function __construct(
        private readonly PurchaseRepository $purchaseRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    public function __invoke(Request $request, ?string $id = null): Response
    {
        $purchase = null;

        if ($id !== null) {
            if (! Ulid::isValid($id)) {
                throw $this->createNotFoundException();
            }

            $purchase = $this->purchaseRepository->find(Ulid::fromString($id));

            if (! $purchase instanceof Purchase) {
                throw $this->createNotFoundException();
            }
        }

        if ($request->isMethod('POST')) {
            return $this->save($request, $purchase);
        }

        return $this->renderForm($purchase, $this->dataFromPurchase($purchase));
    }

    private function save(Request $request, ?Purchase $purchase): Response
    {
        if (! $this->isCsrfTokenValid('purchase.save', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirect($request->getUri());
        }

        $data = [
            'supplier_id' => trim((string) $request->request->get('supplier_id')),
            'reference' => $this->nullify($request->request->get('reference')),
            'purchase_date' => trim((string) $request->request->get('purchase_date')),
            'description' => $this->nullify($request->request->get('description')),
            'total_amount' => trim((string) $request->request->get('total_amount')),
            'amount_paid' => trim((string) $request->request->get('amount_paid')),
        ];

        $supplier = $data['supplier_id'] !== '' && Ulid::isValid($data['supplier_id'])
            ? $this->supplierRepository->find(Ulid::fromString($data['supplier_id']))
            : null;

        if (! $supplier instanceof Supplier) {
            $this->addFlash('error', 'Please choose a supplier.');

            return $this->renderForm($purchase, $data);
        }

        if (! is_numeric($data['total_amount'])) {
            $this->addFlash('error', 'Please enter a valid total amount.');

            return $this->renderForm($purchase, $data);
        }

        $total = BigDecimal::of($data['total_amount'])->toScale(2, RoundingMode::HalfUp);
        $paid = $data['amount_paid'] !== '' && is_numeric($data['amount_paid'])
            ? BigDecimal::of($data['amount_paid'])->toScale(2, RoundingMode::HalfUp)
            : BigDecimal::zero();

        try {
            $purchaseDate = $data['purchase_date'] !== ''
                ? new DateTimeImmutable($data['purchase_date'])
                : new DateTimeImmutable('today');
        } catch (Throwable) {
            $this->addFlash('error', 'Please enter a valid purchase date.');

            return $this->renderForm($purchase, $data);
        }

        if ($purchase === null) {
            $companyId = $this->companySelector->getCompany();
            $company = $companyId !== null ? $this->companyRepository->find($companyId) : null;

            if (! $company instanceof Company) {
                $this->addFlash('error', 'No active company selected.');

                return $this->redirectToRoute('_purchases_list');
            }

            $purchase = new Purchase();
            $purchase->setCompany($company);
        }

        $purchase->setSupplier($supplier)
            ->setReference($data['reference'])
            ->setPurchaseDate($purchaseDate)
            ->setDescription($data['description'])
            ->setTotalAmount((string) $total)
            ->setAmountPaid((string) $paid);

        $this->purchaseRepository->save($purchase);

        $this->addFlash('success', 'Purchase saved.');

        return $this->redirectToRoute('_purchases_list');
    }

    /**
     * @param array<string, string|null> $data
     */
    private function renderForm(?Purchase $purchase, array $data): Response
    {
        return $this->render('@SolidInvoiceCore/Purchase/form.html.twig', [
            'purchase' => $purchase,
            'data' => $data,
            'suppliers' => $this->supplierRepository->findAllOrdered(),
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private function dataFromPurchase(?Purchase $purchase): array
    {
        if (! $purchase instanceof Purchase) {
            return [
                'supplier_id' => '',
                'reference' => null,
                'purchase_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
                'description' => null,
                'total_amount' => '',
                'amount_paid' => '0',
            ];
        }

        return [
            'supplier_id' => $purchase->getSupplier() !== null ? (string) $purchase->getSupplier()->getId() : '',
            'reference' => $purchase->getReference(),
            'purchase_date' => $purchase->getPurchaseDate()?->format('Y-m-d') ?? '',
            'description' => $purchase->getDescription(),
            'total_amount' => $purchase->getTotalAmount(),
            'amount_paid' => $purchase->getAmountPaid(),
        ];
    }

    private function nullify(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
