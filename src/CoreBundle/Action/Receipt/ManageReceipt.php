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

namespace SolidInvoice\CoreBundle\Action\Receipt;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use SolidInvoice\ClientBundle\Entity\Client;
use SolidInvoice\ClientBundle\Repository\ClientRepository;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\CustomerReceipt;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Repository\CustomerReceiptRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use Throwable;
use function is_numeric;
use function trim;

/**
 * Records a customer payment received (money in) that is not tied to a specific
 * B2B invoice - a debtor clearing an old balance, or cash over the counter. It
 * feeds the daily ledger money-in and reduces the customer's outstanding balance.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ManageReceipt extends AbstractController
{
    /**
     * Payment methods offered in the form (free choice - the list is a convenience).
     *
     * @var list<string>
     */
    private const array METHODS = [
        'Cash',
        'Bank Transfer',
        'Cheque',
        'Card',
        'Other',
    ];

    public function __construct(
        private readonly CustomerReceiptRepository $receiptRepository,
        private readonly ClientRepository $clientRepository,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    public function __invoke(Request $request, ?string $id = null): Response
    {
        $receipt = null;

        if ($id !== null) {
            if (! Ulid::isValid($id)) {
                throw $this->createNotFoundException();
            }

            $receipt = $this->receiptRepository->find(Ulid::fromString($id));

            if (! $receipt instanceof CustomerReceipt) {
                throw $this->createNotFoundException();
            }
        }

        if ($request->isMethod('POST')) {
            return $this->save($request, $receipt);
        }

        return $this->renderForm($receipt, $this->dataFromReceipt($receipt));
    }

    private function save(Request $request, ?CustomerReceipt $receipt): Response
    {
        if (! $this->isCsrfTokenValid('receipt.save', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirect($request->getUri());
        }

        $data = [
            'client_id' => trim((string) $request->request->get('client_id')),
            'payer_name' => $this->nullify($request->request->get('payer_name')),
            'receipt_date' => trim((string) $request->request->get('receipt_date')),
            'amount' => trim((string) $request->request->get('amount')),
            'method' => trim((string) $request->request->get('method')) ?: 'Cash',
            'reference' => $this->nullify($request->request->get('reference')),
            'note' => $this->nullify($request->request->get('note')),
        ];

        // Resolve the chosen customer (optional - an ad-hoc cash receipt can be
        // recorded with just a payer name).
        $client = null;
        if ($data['client_id'] !== '' && Ulid::isValid($data['client_id'])) {
            $client = $this->clientRepository->find(Ulid::fromString($data['client_id']));
        }

        if (! $client instanceof Client && $data['payer_name'] === null) {
            $this->addFlash('error', 'Please choose a customer or type who paid.');

            return $this->renderForm($receipt, $data);
        }

        if ($data['amount'] === '' || ! is_numeric($data['amount']) || BigDecimal::of($data['amount'])->isNegativeOrZero()) {
            $this->addFlash('error', 'Please enter an amount greater than zero.');

            return $this->renderForm($receipt, $data);
        }

        try {
            $receiptDate = $data['receipt_date'] !== ''
                ? new DateTimeImmutable($data['receipt_date'])
                : new DateTimeImmutable('today');
        } catch (Throwable) {
            $this->addFlash('error', 'Please enter a valid date.');

            return $this->renderForm($receipt, $data);
        }

        $amount = BigDecimal::of($data['amount'])->toScale(2, RoundingMode::HalfUp);

        if ($receipt === null) {
            $companyId = $this->companySelector->getCompany();
            $company = $companyId !== null ? $this->companyRepository->find($companyId) : null;

            if (! $company instanceof Company) {
                $this->addFlash('error', 'No active company selected.');

                return $this->redirectToRoute('_receipts_list');
            }

            $receipt = new CustomerReceipt();
            $receipt->setCompany($company);
        }

        $receipt->setClient($client)
            ->setPayerName($client instanceof Client ? null : $data['payer_name'])
            ->setReceiptDate($receiptDate)
            ->setAmount((string) $amount)
            ->setMethod($data['method'])
            ->setReference($data['reference'])
            ->setNote($data['note']);

        $this->receiptRepository->save($receipt);

        $this->addFlash('success', 'Payment recorded.');

        return $this->redirectToRoute('_receipts_list');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderForm(?CustomerReceipt $receipt, array $data): Response
    {
        return $this->render('@SolidInvoiceCore/Receipt/form.html.twig', [
            'receipt' => $receipt,
            'data' => $data,
            'methods' => self::METHODS,
            'clients' => $this->clientRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dataFromReceipt(?CustomerReceipt $receipt): array
    {
        if (! $receipt instanceof CustomerReceipt) {
            return [
                'client_id' => '',
                'payer_name' => null,
                'receipt_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
                'amount' => '',
                'method' => 'Cash',
                'reference' => null,
                'note' => null,
            ];
        }

        return [
            'client_id' => $receipt->getClient()?->getId() !== null ? (string) $receipt->getClient()->getId() : '',
            'payer_name' => $receipt->getPayerName(),
            'receipt_date' => $receipt->getReceiptDate()?->format('Y-m-d') ?? '',
            'amount' => $receipt->getAmount(),
            'method' => $receipt->getMethod(),
            'reference' => $receipt->getReference(),
            'note' => $receipt->getNote(),
        ];
    }

    private function nullify(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
