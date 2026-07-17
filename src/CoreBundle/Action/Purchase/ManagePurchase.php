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
use SolidInvoice\ClientBundle\Entity\Client;
use SolidInvoice\ClientBundle\Repository\ClientRepository;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\Purchase;
use SolidInvoice\CoreBundle\Entity\PurchaseItem;
use SolidInvoice\CoreBundle\Entity\PurchasePayment;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Repository\PurchaseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use Throwable;
use function count;
use function is_array;
use function is_numeric;
use function trim;

/**
 * Creates a new purchase order (supplier bill) or edits an existing one. The
 * supplier is chosen from the existing client list, and the purchase is itemised
 * with line items (one row per product) just like an invoice - the total is the
 * sum of the lines.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ManagePurchase extends AbstractController
{
    public function __construct(
        private readonly PurchaseRepository $purchaseRepository,
        private readonly ClientRepository $clientRepository,
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

        $data = $this->dataFromPurchase($purchase);

        // When creating a fresh purchase, allow the supplier to be pre-selected
        // via ?client=<id> (used by the "Create Purchase Order" action on the
        // client page) so the form opens with that supplier already chosen.
        if ($purchase === null) {
            $client = trim((string) $request->query->get('client', ''));

            if ($client !== '' && Ulid::isValid($client)) {
                $data['client_id'] = $client;
            }
        }

        return $this->renderForm($purchase, $data);
    }

    private function save(Request $request, ?Purchase $purchase): Response
    {
        if (! $this->isCsrfTokenValid('purchase.save', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirect($request->getUri());
        }

        $data = [
            'client_id' => trim((string) $request->request->get('client_id')),
            'reference' => $this->nullify($request->request->get('reference')),
            'purchase_date' => trim((string) $request->request->get('purchase_date')),
            'description' => $this->nullify($request->request->get('description')),
            'amount_paid' => trim((string) $request->request->get('amount_paid')),
            'items' => $this->parseItems($request),
            'payments' => $this->parsePayments($request),
        ];

        $client = $data['client_id'] !== '' && Ulid::isValid($data['client_id'])
            ? $this->clientRepository->find(Ulid::fromString($data['client_id']))
            : null;

        if (! $client instanceof Client) {
            $this->addFlash('error', 'Please choose a supplier.');

            return $this->renderForm($purchase, $data);
        }

        if ($data['items'] === []) {
            $this->addFlash('error', 'Please add at least one line item.');

            return $this->renderForm($purchase, $data);
        }

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

        $purchase->setClient($client)
            ->setReference($data['reference'])
            ->setPurchaseDate($purchaseDate)
            ->setDescription($data['description']);

        // Rebuild the line items from scratch on every save (orphanRemoval drops
        // the old rows), then let the purchase total itself from the lines.
        $purchase->clearItems();

        foreach ($data['items'] as $row) {
            $item = new PurchaseItem();
            $item->setDescription($row['description'])
                ->setQty($row['qty'])
                ->setPrice($row['price']);
            $item->recalculateTotal();
            $purchase->addItem($item);
        }

        $purchase->recalculateTotalFromItems();

        // Rebuild the dated payments. If none were entered but a plain amount was
        // (e.g. an older form), treat it as a single payment on the purchase date
        // so nothing is lost. The amount paid is then the sum of the payments.
        $payments = $data['payments'];

        if ($payments === [] && $paid->isPositive()) {
            $payments = [['date' => $purchaseDate->format('Y-m-d'), 'amount' => (string) $paid]];
        }

        $purchase->clearPayments();

        foreach ($payments as $row) {
            try {
                $paymentDate = $row['date'] !== '' ? new DateTimeImmutable($row['date']) : $purchaseDate;
            } catch (Throwable) {
                $paymentDate = $purchaseDate;
            }

            $payment = new PurchasePayment();
            $payment->setPaymentDate($paymentDate)
                ->setAmount($row['amount']);
            $purchase->addPayment($payment);
        }

        $purchase->recalculateAmountPaidFromPayments();

        $this->purchaseRepository->save($purchase);

        $this->addFlash('success', 'Purchase saved.');

        return $this->redirectToRoute('_purchases_list');
    }

    /**
     * Read the parallel item_description[] / item_qty[] / item_price[] arrays into
     * a clean list of rows, skipping blank lines. Quantities/prices default to
     * sensible numbers so a half-filled row never breaks the maths.
     *
     * @return list<array{description: string, qty: string, price: string}>
     */
    private function parseItems(Request $request): array
    {
        $descriptions = $request->request->all('item_description');
        $quantities = $request->request->all('item_qty');
        $prices = $request->request->all('item_price');

        if (! is_array($descriptions)) {
            return [];
        }

        $rows = [];
        $count = count($descriptions);

        for ($i = 0; $i < $count; $i++) {
            $description = trim((string) ($descriptions[$i] ?? ''));
            $qtyRaw = trim((string) ($quantities[$i] ?? ''));
            $priceRaw = trim((string) ($prices[$i] ?? ''));

            // Ignore a completely empty row.
            if ($description === '' && $qtyRaw === '' && $priceRaw === '') {
                continue;
            }

            $qty = is_numeric($qtyRaw) ? (string) BigDecimal::of($qtyRaw)->toScale(2, RoundingMode::HalfUp) : '1.00';
            $price = is_numeric($priceRaw) ? (string) BigDecimal::of($priceRaw)->toScale(2, RoundingMode::HalfUp) : '0.00';

            $rows[] = [
                'description' => $description !== '' ? $description : 'Item',
                'qty' => $qty,
                'price' => $price,
            ];
        }

        return $rows;
    }

    /**
     * Read the parallel payment_date[] / payment_amount[] arrays into a clean list
     * of dated payments, skipping blank rows and any row without a positive amount.
     *
     * @return list<array{date: string, amount: string}>
     */
    private function parsePayments(Request $request): array
    {
        $dates = $request->request->all('payment_date');
        $amounts = $request->request->all('payment_amount');

        if (! is_array($amounts)) {
            return [];
        }

        $rows = [];
        $count = count($amounts);

        for ($i = 0; $i < $count; $i++) {
            $date = trim((string) (is_array($dates) ? ($dates[$i] ?? '') : ''));
            $amountRaw = trim((string) ($amounts[$i] ?? ''));

            if ($date === '' && $amountRaw === '') {
                continue;
            }

            if (! is_numeric($amountRaw)) {
                continue;
            }

            $amount = BigDecimal::of($amountRaw)->toScale(2, RoundingMode::HalfUp);

            if ($amount->isNegativeOrZero()) {
                continue;
            }

            $rows[] = [
                'date' => $date,
                'amount' => (string) $amount,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderForm(?Purchase $purchase, array $data): Response
    {
        return $this->render('@SolidInvoiceCore/Purchase/form.html.twig', [
            'purchase' => $purchase,
            'data' => $data,
            'clients' => $this->clientRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dataFromPurchase(?Purchase $purchase): array
    {
        if (! $purchase instanceof Purchase) {
            return [
                'client_id' => '',
                'reference' => null,
                'purchase_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
                'description' => null,
                'amount_paid' => '0',
                'items' => [],
                'payments' => [],
            ];
        }

        $items = [];

        foreach ($purchase->getItems() as $item) {
            $items[] = [
                'description' => $item->getDescription(),
                'qty' => $item->getQty(),
                'price' => $item->getPrice(),
            ];
        }

        $purchaseDate = $purchase->getPurchaseDate()?->format('Y-m-d') ?? '';

        $payments = [];

        foreach ($purchase->getPayments() as $payment) {
            $payments[] = [
                'date' => $payment->getPaymentDate()?->format('Y-m-d') ?? $purchaseDate,
                'amount' => $payment->getAmount(),
            ];
        }

        // Older purchases recorded a single "amount paid" with no dated payments.
        // Seed one payment row from it (dated the purchase date) so opening the
        // form preserves it and the user can split it across the real dates.
        if ($payments === [] && is_numeric($purchase->getAmountPaid()) && BigDecimal::of($purchase->getAmountPaid())->isPositive()) {
            $payments[] = [
                'date' => $purchaseDate,
                'amount' => $purchase->getAmountPaid(),
            ];
        }

        return [
            'client_id' => $purchase->getClient() !== null ? (string) $purchase->getClient()->getId() : '',
            'reference' => $purchase->getReference(),
            'purchase_date' => $purchaseDate,
            'description' => $purchase->getDescription(),
            'amount_paid' => $purchase->getAmountPaid(),
            'items' => $items,
            'payments' => $payments,
        ];
    }

    private function nullify(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
