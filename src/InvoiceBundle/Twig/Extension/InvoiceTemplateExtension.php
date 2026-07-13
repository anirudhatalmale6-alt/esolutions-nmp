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
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\PaymentBundle\Entity\Payment;
use SolidInvoice\PaymentBundle\Enum\PaymentStatus;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use function array_filter;
use function array_values;

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
        ];
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
