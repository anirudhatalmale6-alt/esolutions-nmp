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

namespace SolidInvoice\InvoiceBundle\Action;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Mpdf\MpdfException;
use SolidInvoice\CoreBundle\Entity\CreditNote;
use SolidInvoice\CoreBundle\Pdf\Generator;
use SolidInvoice\CoreBundle\Repository\CreditNoteRepository;
use SolidInvoice\CoreBundle\Response\PdfResponse;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\PaymentBundle\Repository\PaymentRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use function array_key_exists;
use function is_array;
use function json_decode;

/**
 * @see \SolidInvoice\InvoiceBundle\Tests\Action\ViewTest
 */
final readonly class View
{
    public function __construct(
        private PaymentRepository $paymentRepository,
        private Generator $pdfGenerator,
        private Environment $twig,
        private CreditNoteRepository $creditNoteRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * @return array{invoice: Invoice, payments: array<string, mixed>}|Response
     * @throws LoaderError
     * @throws MpdfException
     * @throws RuntimeError
     * @throws SyntaxError
     */
    #[Template('@SolidInvoiceInvoice/Default/view.html.twig')]
    public function __invoke(Request $request, Invoice $invoice): array | Response
    {
        if ('pdf' === $request->getRequestFormat() && $this->pdfGenerator->canPrintPdf()) {
            // Any refunds / credit notes raised against this invoice, so the PDF the
            // customer receives shows the deducted (net) amount instead of the full
            // total. Same filter-lifting as the on-screen view (see below).
            $creditNotes = $this->withoutFilters(
                ['company', 'archivable'],
                fn (): array => $this->creditNoteRepository->findForInvoice($invoice)
            );

            [$refunds, $refundTotalMinor, $netTotalMinor] = $this->refundTotals($invoice, $creditNotes);

            return new PdfResponse($this->pdfGenerator->generate($this->twig->render('@SolidInvoiceInvoice/Pdf/invoice.html.twig', [
                'invoice' => $invoice,
                'refunds' => $refunds,
                'refundTotalMinor' => $refundTotalMinor,
                'netTotalMinor' => $netTotalMinor,
            ])), sprintf('invoice_%s.pdf', $invoice->getInvoiceId()));
        }

        // Credit notes for THIS invoice, with the company/archivable SQL filters
        // temporarily lifted. Those filters scope queries to the viewer's active
        // company context, which can differ from the invoice's own company and then
        // wrongly hides the invoice's refunds. We're already looking at one specific,
        // authorised invoice, so returning its own credit notes is always safe.
        $creditNotes = $this->withoutFilters(
            ['company', 'archivable'],
            fn (): array => $this->creditNoteRepository->findForInvoice($invoice)
        );

        return [
            'invoice' => $invoice,
            'payments' => $this->paymentRepository->getPaymentsForInvoice($invoice),
            'creditNotes' => $creditNotes,
            // Net units returned per invoice line, read straight from the table so
            // the "net qty (X returned)" display can never be filtered away.
            'returnedByLine' => $this->returnedByLine($invoice),
        ];
    }

    /**
     * Build the refund lines and the net total for the PDF. Credit note amounts are
     * stored in major units (e.g. "500.00"), while the PDF formats money from minor
     * units, so each amount is scaled up by 100 before it is summed and subtracted
     * from the invoice total.
     *
     * @param list<CreditNote> $creditNotes
     *
     * @return array{0: list<array{label: ?string, storeCredit: bool, amountMinor: string}>, 1: string, 2: string}
     */
    private function refundTotals(Invoice $invoice, array $creditNotes): array
    {
        $refunds = [];
        $refundTotalMinor = BigInteger::zero();

        foreach ($creditNotes as $creditNote) {
            $amountMinor = BigDecimal::of($creditNote->getAmount())
                ->multipliedBy(100)
                ->toScale(0, RoundingMode::HALF_UP)
                ->toBigInteger();

            $refundTotalMinor = $refundTotalMinor->plus($amountMinor);

            $refunds[] = [
                'label' => $creditNote->getReason(),
                'storeCredit' => $creditNote->isStoreCredit(),
                'amountMinor' => (string) $amountMinor,
            ];
        }

        $netTotalMinor = $invoice->getTotal()->toBigInteger()->minus($refundTotalMinor);

        return [$refunds, (string) $refundTotalMinor, (string) $netTotalMinor];
    }

    /**
     * Total units returned per invoice line (invoice_line id => qty), summed across
     * every credit note for the invoice. Read via raw DBAL so it is immune to the
     * ORM's company/archivable filters.
     *
     * @return array<string, float>
     */
    private function returnedByLine(Invoice $invoice): array
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            'SELECT returned_lines FROM credit_note WHERE invoice_id = :id',
            ['id' => $invoice->getId()->toBinary()],
            ['id' => ParameterType::BINARY]
        )->fetchFirstColumn();

        $returnedByLine = [];

        foreach ($rows as $json) {
            if ($json === null || $json === '') {
                continue;
            }

            $decoded = json_decode((string) $json, true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $lineId => $qty) {
                $lineId = (string) $lineId;
                $returnedByLine[$lineId] = ($returnedByLine[$lineId] ?? 0.0) + (float) $qty;
            }
        }

        return $returnedByLine;
    }

    /**
     * Runs $callback with the given Doctrine SQL filters disabled, restoring
     * whatever was enabled afterwards (even if the callback throws).
     *
     * @param list<string> $filterNames
     */
    private function withoutFilters(array $filterNames, callable $callback): mixed
    {
        $filters = $this->entityManager->getFilters();
        $restore = [];

        foreach ($filterNames as $name) {
            if (array_key_exists($name, $filters->getEnabledFilters())) {
                $filters->disable($name);
                $restore[] = $name;
            }
        }

        try {
            return $callback();
        } finally {
            foreach ($restore as $name) {
                $filters->enable($name);
            }
        }
    }
}
