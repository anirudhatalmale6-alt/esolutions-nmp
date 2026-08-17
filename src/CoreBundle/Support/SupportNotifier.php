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

namespace SolidInvoice\CoreBundle\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\MarketplaceSetting;
use SolidInvoice\CoreBundle\Entity\SupportTicket;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;
use function preg_replace;
use function rawurlencode;
use function trim;

/**
 * Tells a member that their support message has been answered.
 *
 * Until this existed, answering a ticket wrote the reply into the portal and
 * nothing else happened: the member saw it the next time they signed in and
 * happened to open Help & Support. For a business that raised a problem and
 * then went back to work, that is indistinguishable from being ignored - which
 * is exactly how it was reported ("saad zahid never received any message").
 *
 * Two ways out, because on this hosting one of them cannot be relied on:
 *
 *   - an email, sent best-effort. A mail server that is down, misconfigured or
 *     blocking outbound SMTP must never cost the owner his reply, so every
 *     failure is caught, logged, and reported back to him honestly. "Handed to
 *     the mail server" is the most that can truthfully be claimed - what happens
 *     after that is out of our hands.
 *   - a WhatsApp link, built from the number the business already gave us. That
 *     is the channel these members actually read, and it cannot fail quietly:
 *     either WhatsApp opens with the message in it or it does not.
 */
final class SupportNotifier
{
    /** The email went to the mail server without an error. */
    public const string SENT = 'sent';

    /** Nobody to write to - the ticket has no email address on it. */
    public const string NO_ADDRESS = 'no-address';

    /** The mail server refused it, or there is no mail server configured. */
    public const string FAILED = 'failed';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $router,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Email the person who raised the ticket that there is an answer waiting.
     *
     * @return array{outcome: string, address: string}
     */
    public function ownerReplied(SupportTicket $ticket, string $body, bool $closed): array
    {
        $address = trim((string) $ticket->getRaisedByEmail());

        if ($address === '') {
            return ['outcome' => self::NO_ADDRESS, 'address' => ''];
        }

        try {
            $email = (new Email())
                ->to(Address::create($address))
                ->subject('Re: ' . $ticket->getSubject())
                ->text($this->emailBody($ticket, $body, $closed));

            // From is filled in by EmailFromListener from the system settings.
            $this->mailer->send($email);

            return ['outcome' => self::SENT, 'address' => $address];
        } catch (Throwable $e) {
            // Never let this take the reply down with it: the reply is already
            // saved, and the owner still has the WhatsApp button.
            $this->logger->error('Support reply notification could not be sent', [
                'ticket' => (string) $ticket->getId(),
                'exception' => $e->getMessage(),
            ]);

            return ['outcome' => self::FAILED, 'address' => $address];
        }
    }

    /**
     * A WhatsApp deep link carrying one reply, addressed to the business's own
     * number. Null when we have no number for them - a wa.me link with no number
     * opens a contact picker, which is worse than no button at all.
     */
    public function whatsappUrl(SupportTicket $ticket, string $body): ?string
    {
        $number = $this->numberFor($ticket->getCompany());

        if ($number === '') {
            return null;
        }

        $text = 'Re: ' . $ticket->getSubject() . "\n\n"
            . trim($body) . "\n\n"
            . 'You can reply here, or on the portal under Help & Support.';

        return 'https://wa.me/' . $number . '?text=' . rawurlencode($text);
    }

    /**
     * The number to reach this business on, digits only for wa.me.
     *
     * The Marketplace WhatsApp number first - it is the one they nominated for
     * being contacted on - then the contact number on the company itself.
     */
    public function numberFor(?Company $company): string
    {
        if (! $company instanceof Company) {
            return '';
        }

        $id = $company->getId();
        $whatsapp = '';

        if ($id !== null) {
            try {
                $whatsapp = (string) ($this->connection->fetchOne(
                    'SELECT whatsapp FROM ' . MarketplaceSetting::TABLE_NAME . ' WHERE company_id = ?',
                    [$id->toBinary()],
                    [ParameterType::BINARY]
                ) ?: '');
            } catch (Throwable) {
                // No settings row, or the table is not there yet. The company's
                // own contact number is the fallback, so say nothing.
                $whatsapp = '';
            }
        }

        return $this->digits($whatsapp) ?: $this->digits((string) $company->getContactNumber());
    }

    private function emailBody(SupportTicket $ticket, string $body, bool $closed): string
    {
        $name = trim((string) $ticket->getRaisedByName());
        $hello = $name === '' ? 'Hello,' : 'Hello ' . $name . ',';

        $text = $hello . "\n\n"
            . 'We have replied to your message "' . $ticket->getSubject() . '":' . "\n\n"
            . trim($body) . "\n\n";

        if ($closed) {
            $text .= 'We have marked this one as closed. If it comes back, open a new one and we will pick it up.' . "\n\n";
        }

        $link = $this->supportUrl();

        if ($link !== '') {
            $text .= 'The whole conversation is on your Help & Support page, and you can reply to us there:' . "\n"
                . $link . "\n\n";
        }

        return $text . 'Thank you.';
    }

    /**
     * An absolute link to the member's own support page. Empty when it cannot be
     * built - a command line has no host to build one from, and a broken link in
     * the email would be worse than none.
     */
    private function supportUrl(): string
    {
        try {
            return $this->router->generate('_support', [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (Throwable) {
            return '';
        }
    }

    private function digits(string $number): string
    {
        return (string) preg_replace('/\D+/', '', $number);
    }
}
