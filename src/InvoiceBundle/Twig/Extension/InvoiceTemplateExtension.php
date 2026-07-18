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

namespace SolidInvoice\InvoiceBundle\Twig\Extension;

use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Override;
use SolidInvoice\ClientBundle\Entity\Contact;
use SolidInvoice\InvoiceBundle\DataGrid\InvoiceStatusView;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\InvoiceBundle\Enum\InvoiceStatus;
use SolidInvoice\PaymentBundle\Entity\Payment;
use SolidInvoice\PaymentBundle\Enum\PaymentStatus;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use function array_filter;
use function array_values;
use function in_array;

/**
 * @see \SolidInvoice\InvoiceBundle\Tests\Twig\Extension\InvoiceTemplateExtensionTest
 */
final class InvoiceTemplateExtension extends AbstractExtension
{
    /**
     * @return TwigFunction[]
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('invoice_has_outstanding_balance', $this->hasOutstandingBalance(...)),
            new TwigFunction('invoice_captured_payments', $this->capturedPayments(...)),
            new TwigFunction('invoice_primary_contact', $this->primaryContact(...)),
            new TwigFunction('invoice_payment_status', $this->paymentStatus(...)),
            new TwigFunction(
                'invoice_status_label',
                $this->invoiceStatusLabel(...),
                ['is_safe' => ['html'], 'needs_environment' => true]
            ),
        ];
    }

    /**
     * Status display for an invoice that reflects partial payments. The
     * InvoiceStatus enum has no "partially paid" state, so an invoice that has
     * received a deposit still carries "pending"/"active"/"overdue". When captured
     * payments exist but the balance is not settled, we surface a "Partially Paid"
     * label; otherwise we fall back to the normal status label and colour.
     */
    public function invoiceStatusView(Invoice $invoice): InvoiceStatusView
    {
        $status = $invoice->getStatus();

        $overridable = in_array($status, [InvoiceStatus::Pending, InvoiceStatus::Active, InvoiceStatus::Overdue], true);

        if ($overridable && $this->paymentStatus($invoice)['isPartiallyPaid']) {
            $name = InvoiceStatus::Overdue === $status ? 'Partially Paid (Overdue)' : 'Partially Paid';

            return new InvoiceStatusView($name, 'orange');
        }

        return new InvoiceStatusView($status->getLabel(), $status->getColor());
    }

    /**
     * Renders the coloured status badge for the invoice list. Accepts the
     * {@see InvoiceStatusView} produced by the grid column's formatValue.
     */
    public function invoiceStatusLabel(Environment $environment, ?InvoiceStatusView $view = null, ?string $tooltip = null): string
    {
        if (! $view instanceof InvoiceStatusView) {
            return '';
        }

        return $environment->render(
            '@SolidInvoiceCore/Status/label.html.twig',
            [
                'entity' => ['name' => $view->name, 'label' => $view->color],
                'tooltip' => $tooltip,
            ]
        );
    }

    /**
     * Reliable paid / balance figures computed from captured payments, in the
     * same minor-unit scale as the invoice total. This does NOT rely on the
     * stored balance field, which stays 0 until an invoice is activated and so
     * cannot be trusted for pending invoices that already have a deposit.
     *
     * @return array{paid: BigInteger, balance: BigNumber, isPaid: bool, isPartiallyPaid: bool}
     */
    public function paymentStatus(Invoice $invoice): array
    {
        $total = $invoice->getTotal();
        $paid = BigInteger::zero();

        foreach ($this->capturedPayments($invoice) as $payment) {
            $paid = $paid->plus($payment->getTotalAmount());
        }

        $balance = $total->minus($paid);

        return [
            'paid' => $paid,
            'balance' => $balance,
            'isPaid' => $total->isPositive() && $balance->isNegativeOrZero(),
            'isPartiallyPaid' => $paid->isPositive() && $balance->isPositive(),
        ];
    }

    public function hasOutstandingBalance(Invoice $invoice): bool
    {
        if (! $invoice->getBalance()->isPositive()) {
            return false;
        }

        return $this->capturedPayments($invoice) !== [];
    }

    /**
     * @return list<Payment>
     */
    public function capturedPayments(Invoice $invoice): array
    {
        return array_values(array_filter(
            $invoice->getPayments()->toArray(),
            static fn (Payment $payment): bool => $payment->getStatus() === PaymentStatus::Captured,
        ));
    }

    public function primaryContact(Invoice $invoice): ?Contact
    {
        $first = $invoice->getUsers()->first();

        return $first instanceof Contact ? $first : null;
    }
}
