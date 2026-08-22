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

namespace SolidInvoice\CoreBundle\Action;

use Brick\Math\BigNumber;
use Money\Currency;
use Money\Money;
use SolidInvoice\CoreBundle\WhatsApp\WhatsAppSender;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\InvoiceBundle\Repository\InvoiceRepository;
use SolidInvoice\MoneyBundle\Formatter\MoneyFormatterInterface;
use SolidInvoice\QuoteBundle\Entity\Quote;
use SolidInvoice\QuoteBundle\Repository\QuoteRepository;
use SolidInvoice\SettingsBundle\SystemConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Ulid;
use function sprintf;
use function substr;
use function trim;

/**
 * Sends an invoice or quote to the customer through the WhatsApp gateway.
 *
 * There are two ways off this screen and they are not the same thing. The
 * wa.me link hands the message to the user's OWN WhatsApp - a person pressing
 * send, from their own account, which WhatsApp has never objected to. This
 * sends it from the gateway instead: no second window, no second click, but the
 * traffic is automated and coming from a number the customer has probably not
 * saved, which is what gets a gateway number restricted.
 *
 * So this is deliberately not the only route. When it refuses, or when there is
 * no gateway, the screen still offers the wa.me box - an invoice that could not
 * be sent must never look like an invoice that was.
 */
final class SendOnWhatsApp extends AbstractController
{
    /**
     * Long enough to be a useful message, short enough that WhatsApp does not
     * collapse it behind a "read more" before the link is visible.
     */
    private const int MAX_DETAIL = 300;

    public function __construct(
        private readonly WhatsAppSender $whatsAppSender,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly QuoteRepository $quoteRepository,
        private readonly MoneyFormatterInterface $moneyFormatter,
        private readonly SystemConfig $systemConfig,
    ) {
    }

    public function __invoke(Request $request, string $type, string $id): Response
    {
        if (! $this->isCsrfTokenValid('whatsapp_send', $request->request->get('_csrf_token'))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $ulid = Ulid::isValid($id) ? Ulid::fromString($id) : null;

        // The repositories are company-filtered, so another business's invoice
        // is simply not found here - it does not need a separate check, and one
        // written here could only ever disagree with the filter.
        $document = $ulid === null ? null : match ($type) {
            'invoice' => $this->invoiceRepository->find($ulid),
            'quote' => $this->quoteRepository->find($ulid),
            default => null,
        };

        if (! $document instanceof Invoice && ! $document instanceof Quote) {
            throw new NotFoundHttpException('No such invoice or quote.');
        }

        $back = $document instanceof Invoice
            ? $this->redirectToRoute('_invoices_view', ['id' => $id])
            : $this->redirectToRoute('_quotes_view', ['id' => $id]);

        $number = trim((string) ($request->request->get('number') ?: $document->getClient()?->getWhatsapp()));

        if ($number === '') {
            $this->addFlash('error', 'Nothing was sent - this customer has no WhatsApp number saved. Add one on the client, or use Open WhatsApp to pick the contact yourself.');

            return $back;
        }

        if (WhatsAppSender::chatId($number) === null) {
            $this->addFlash('error', sprintf(
                'Nothing was sent - %s is not a number WhatsApp can reach. It needs the country code, for example 971501234567.',
                $number,
            ));

            return $back;
        }

        if (! $this->whatsAppSender->isConfigured()) {
            $this->addFlash('error', 'Nothing was sent - the WhatsApp gateway is not connected. Use Open WhatsApp to send it from your own phone, or set the gateway up under Settings, WhatsApp.');

            return $back;
        }

        $result = $this->whatsAppSender->send($number, $this->message($document));

        if ($result->sent) {
            $this->addFlash('success', sprintf('Sent to %s on WhatsApp.', $number));

            return $back;
        }

        // Naming the other route matters more than the reason does: he is
        // standing in front of a customer who is waiting for the invoice.
        $this->addFlash('error', sprintf(
            'WhatsApp did not send it, so please use Open WhatsApp to send it from your own phone. The gateway said: %s',
            substr($result->detail, 0, self::MAX_DETAIL),
        ));

        return $back;
    }

    /**
     * The same one-line summary the wa.me box puts in the chat, so a customer
     * gets the same message whichever way it went.
     */
    private function message(Invoice | Quote $document): string
    {
        // getCurrencyCode(), not getCurrency(): the latter returns an
        // uninitialised typed property when a client has no currency code, so
        // asking it is a fatal error on exactly the records most likely to be
        // half-filled in. The system currency is the same fallback the
        // formatCurrency filter uses, so the message reads like the screen.
        $code = trim((string) $document->getClient()?->getCurrencyCode());

        $total = $this->moneyFormatter->format(
            new Money(
                (string) BigNumber::of($document->getTotal())->toBigDecimal()->toScale(0),
                $code !== '' ? new Currency($code) : $this->systemConfig->getCurrency(),
            ),
        );

        if ($document instanceof Invoice) {
            return sprintf(
                'Invoice %s - Total: %s - View: %s',
                $document->getInvoiceId(),
                $total,
                $this->generateUrl('_view_invoice_external', ['uuid' => $document->getUuid()], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        }

        return sprintf(
            'Quote %s - Total: %s - View: %s',
            $document->getQuoteId(),
            $total,
            $this->generateUrl('_view_quote_external', ['uuid' => $document->getUuid()], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }
}
