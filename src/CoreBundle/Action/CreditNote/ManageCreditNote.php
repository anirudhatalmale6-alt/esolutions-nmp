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

namespace SolidInvoice\CoreBundle\Action\CreditNote;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use SolidInvoice\ClientBundle\Entity\Client;
use SolidInvoice\ClientBundle\Repository\CreditRepository;
use SolidInvoice\CoreBundle\Entity\CreditNote;
use SolidInvoice\CoreBundle\Repository\CreditNoteRepository;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\InvoiceBundle\Repository\InvoiceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use Throwable;
use function in_array;
use function is_numeric;
use function trim;

/**
 * Records a customer refund / credit note against an invoice. The customer
 * returned goods; they are refunded either as cash paid back out or as store
 * credit added to their account. This handles money + record only - stock is
 * governed by the Tally import (see CreditNote for the rationale).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ManageCreditNote extends AbstractController
{
    public function __construct(
        private readonly CreditNoteRepository $creditNoteRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly CreditRepository $creditRepository,
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(Request $request, string $invoiceId): Response
    {
        if (! Ulid::isValid($invoiceId)) {
            throw $this->createNotFoundException();
        }

        $invoice = $this->invoiceRepository->find(Ulid::fromString($invoiceId));

        if (! $invoice instanceof Invoice) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            return $this->save($request, $invoice);
        }

        return $this->renderForm($invoice, $this->defaults($invoice));
    }

    private function save(Request $request, Invoice $invoice): Response
    {
        if (! $this->isCsrfTokenValid('creditnote.save', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirect($request->getUri());
        }

        $data = [
            'credit_date' => trim((string) $request->request->get('credit_date')),
            'amount' => trim((string) $request->request->get('amount')),
            'refund_type' => trim((string) $request->request->get('refund_type')),
            'disposition' => trim((string) $request->request->get('disposition')),
            'reference' => $this->nullify($request->request->get('reference')),
            'reason' => $this->nullify($request->request->get('reason')),
        ];

        if ($data['amount'] === '' || ! is_numeric($data['amount']) || BigDecimal::of($data['amount'])->isNegativeOrZero()) {
            $this->addFlash('error', 'Please enter a refund amount greater than zero.');

            return $this->renderForm($invoice, $data);
        }

        if (! in_array($data['refund_type'], [CreditNote::TYPE_CASH, CreditNote::TYPE_CREDIT], true)) {
            $data['refund_type'] = CreditNote::TYPE_CASH;
        }

        if (! in_array($data['disposition'], [CreditNote::DISPOSITION_REPAIRED, CreditNote::DISPOSITION_BER], true)) {
            $data['disposition'] = '';
        }

        $client = $invoice->getClient();

        if (! $client instanceof Client) {
            $this->addFlash('error', 'This invoice has no client, cannot record a refund.');

            return $this->redirectToRoute('_invoices_view', ['id' => (string) $invoice->getId()]);
        }

        try {
            $creditDate = $data['credit_date'] !== ''
                ? new DateTimeImmutable($data['credit_date'])
                : new DateTimeImmutable('today');
        } catch (Throwable) {
            $this->addFlash('error', 'Please enter a valid date.');

            return $this->renderForm($invoice, $data);
        }

        $amount = BigDecimal::of($data['amount'])->toScale(2, RoundingMode::HalfUp);

        $creditNote = new CreditNote();
        $creditNote->setCompany($invoice->getCompany())
            ->setInvoice($invoice)
            ->setClient($client)
            ->setCreditDate($creditDate)
            ->setAmount((string) $amount)
            ->setRefundType($data['refund_type'])
            ->setDisposition($data['disposition'] !== '' ? $data['disposition'] : null)
            ->setReference($data['reference'])
            ->setReason($data['reason']);

        $this->creditNoteRepository->save($creditNote);

        // Store credit adds to the client's credit balance, which is held in
        // MINOR units (fils), so the major-unit refund amount is multiplied by 100.
        if ($creditNote->isStoreCredit()) {
            $minor = (string) $amount->multipliedBy(100)->toScale(0, RoundingMode::HalfUp);
            $this->creditRepository->addCredit($client, $minor);
            $this->addFlash('success', 'Credit note saved and ' . (string) $amount . ' added to ' . $client->getName() . '\'s store credit.');
        } else {
            $this->addFlash('success', 'Credit note saved. ' . (string) $amount . ' recorded as refunded (money out).');
        }

        return $this->redirectToRoute('_invoices_view', ['id' => (string) $invoice->getId()]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderForm(Invoice $invoice, array $data): Response
    {
        return $this->render('@SolidInvoiceCore/CreditNote/form.html.twig', [
            'invoice' => $invoice,
            'data' => $data,
            'lines' => $this->invoiceLines($invoice),
            'invoiceTotal' => $this->toMajor((string) $invoice->getTotal()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(Invoice $invoice): array
    {
        return [
            'credit_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
            'amount' => '',
            'refund_type' => CreditNote::TYPE_CASH,
            'disposition' => '',
            'reference' => null,
            'reason' => null,
        ];
    }

    /**
     * The invoice's line items (description, qty, unit price, line total), all in
     * major units. Read via raw DBAL - same robust approach as the sales reports.
     * Used purely to help the user pick what is coming back; the saved amount is
     * whatever ends up in the amount field.
     *
     * @return list<array{description: string, qty: string, price: string, total: string}>
     */
    private function invoiceLines(Invoice $invoice): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT il.description AS description,
                    il.qty AS qty,
                    il.price_amount AS price,
                    il.total_amount AS total
             FROM invoice_lines il
             WHERE il.invoice_id = :invoiceId
             ORDER BY il.id ASC',
            ['invoiceId' => $invoice->getId()->toBinary()],
            ['invoiceId' => ParameterType::BINARY]
        )->fetchAllAssociative();

        $lines = [];

        foreach ($rows as $row) {
            $qty = (string) ($row['qty'] ?? '0');

            if (str_contains($qty, '.')) {
                $qty = rtrim(rtrim($qty, '0'), '.');
            }

            $lines[] = [
                'description' => (string) ($row['description'] ?? ''),
                'qty' => $qty === '' ? '0' : $qty,
                'price' => $this->toMajor((string) ($row['price'] ?? '0')),
                'total' => $this->toMajor((string) ($row['total'] ?? '0')),
            ];
        }

        return $lines;
    }

    private function toMajor(string $minor): string
    {
        if ($minor === '' || ! is_numeric($minor)) {
            return '0.00';
        }

        return (string) BigDecimal::of($minor)->dividedBy(100, 2, RoundingMode::HalfUp);
    }

    private function nullify(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
