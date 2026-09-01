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

namespace SolidInvoice\CoreBundle\Verification;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Platform;
use SolidInvoice\CoreBundle\WhatsApp\WhatsAppSender;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;
use function array_map;
use function in_array;
use function trim;

/**
 * Tells somebody that a verification moved.
 *
 * The trusted badge worked from the day it was built, but silently in both
 * directions: a business uploaded its passport and heard nothing ever again,
 * and the owner only discovered the documents by opening Manage and looking.
 * A queue nobody is told about is a queue that sits - so a business that signed
 * up on a Friday could wait a week without a single person knowing there was
 * anything to do.
 *
 * Everything here is best-effort and swallows its own failures. The upload is
 * already on disk and the badge is already granted by the time any of this
 * runs; a mail server that is refusing connections this morning must not undo
 * either of them, or report them as failed to the person who just did the work.
 *
 * @see \SolidInvoice\CoreBundle\Support\SupportNotifier the same pattern, for
 *      the support desk - raw SQL for the recipients, for the same reason
 */
final readonly class VerificationAlerts
{
    public function __construct(
        private MailerInterface $mailer,
        private Connection $connection,
        private UrlGeneratorInterface $router,
        private LoggerInterface $logger,
        private WhatsAppSender $whatsapp,
    ) {
    }

    /**
     * A business has sent its documents in and is waiting to be looked at.
     */
    public function documentsSubmitted(Company $company): void
    {
        $addresses = $this->ownerAddresses();

        if ($addresses === []) {
            return;
        }

        $business = trim($company->getName());
        $based = $this->basedIn($company);
        $number = trim((string) $company->getContactNumber());

        $body = 'A business has sent documents in for the trusted badge.' . "\n\n"
            . 'Business: ' . ($business === '' ? 'unnamed' : $business) . "\n"
            . ($based === '' ? '' : 'Based in: ' . $based . "\n")
            . ($number === '' ? '' : 'Contact number: ' . $number . "\n")
            . "\n"
            . 'Open their documents and grant the badge here:' . "\n"
            . $this->url('_membership_manage', 'They are waiting on the Manage page.') . "\n";

        $this->send(
            $addresses,
            ($business === '' ? 'A business' : $business) . ' is waiting for verification',
            $body,
            'documents-submitted',
            $company
        );
    }

    /**
     * The owner has ticked Verified - tell the business it went through.
     *
     * Sent only when the badge is newly granted. Saving the Manage form for any
     * other reason must not send it again, and taking a badge away is a
     * conversation the owner will want to have himself rather than by robot.
     */
    public function badgeGranted(Company $company): void
    {
        $business = trim($company->getName());
        $greeting = $business === '' ? 'Hello,' : 'Hello ' . $business . ',';

        $body = $greeting . "\n\n"
            . 'Your business is now verified on ' . Platform::NAME . '.' . "\n\n"
            . 'The blue tick shows next to your name in the marketplace and on anything you post, '
            . 'so other members can see at a glance that we have checked who you are.' . "\n\n"
            . 'Sign in here:' . "\n"
            . $this->url('_dashboard', 'Sign in to the portal to see it.') . "\n\n"
            . 'Thank you for sending your documents in.';

        $addresses = $this->memberAddresses($company);

        if ($addresses !== []) {
            $this->send(
                $addresses,
                'You are now verified on ' . Platform::NAME,
                $body,
                'badge-granted',
                $company
            );
        }

        // Email to this audience has a history of landing in junk, and the badge
        // is good news that should not depend on one channel. WhatsApp is
        // best-effort on top - it refuses a non-international number by itself
        // and never throws.
        $number = trim((string) $company->getContactNumber());

        if ($number === '') {
            return;
        }

        try {
            if (! $this->whatsapp->isConfigured()) {
                return;
            }

            $result = $this->whatsapp->send(
                $number,
                'Good news - your business is now verified on ' . Platform::NAME . '. '
                . 'The blue tick is on your name in the marketplace from now on.'
            );

            if (! $result->sent) {
                $this->logger->error('Verification badge WhatsApp was not delivered', [
                    'company' => (string) $company->getId(),
                    'reason' => $result->detail,
                ]);
            }
        } catch (Throwable $e) {
            // isConfigured() reads settings, which reads the database.
            $this->logger->error('Verification badge WhatsApp could not be attempted', [
                'company' => (string) $company->getId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param list<string> $addresses
     */
    private function send(array $addresses, string $subject, string $body, string $kind, Company $company): void
    {
        try {
            $email = (new Email())
                ->to(...array_map(static fn (string $a): Address => Address::create($a), $addresses))
                ->subject($subject)
                ->text($body);

            // From is filled in by EmailFromListener from the system settings.
            $this->mailer->send($email);
        } catch (Throwable $e) {
            $this->logger->error('Verification notification could not be sent', [
                'kind' => $kind,
                'company' => (string) $company->getId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The platform owner's accounts.
     *
     * Raw SQL on purpose: the company filter is scoped to the MEMBER's business
     * while their upload is being handled, and the owner is not in it.
     *
     * @return list<string>
     */
    private function ownerAddresses(): array
    {
        return $this->addressesFrom(
            'SELECT email FROM ' . User::TABLE_NAME . " WHERE roles LIKE '%ROLE_SUPER_ADMIN%' AND email IS NOT NULL AND email <> ''"
        );
    }

    /**
     * Who to write to at one business.
     *
     * Its admin accounts if it has any - the people who own the workspace and
     * who sent the documents in - and everybody on it if it somehow has none,
     * because an approval nobody is told about is the bug this exists to fix.
     * Raw SQL again: this runs while the SUPER USER is signed in, so the filter
     * is scoped to his company rather than to theirs.
     *
     * @return list<string>
     */
    private function memberAddresses(Company $company): array
    {
        $sql = 'SELECT u.email FROM ' . User::TABLE_NAME . ' u'
            . ' INNER JOIN user_company uc ON uc.user_id = u.id'
            . ' WHERE uc.company_id = ? AND u.email IS NOT NULL AND u.email <> \'\'';

        $params = [$company->getId()->toBinary()];
        $types = [ParameterType::BINARY];

        $admins = $this->addressesFrom($sql . " AND u.roles LIKE '%ROLE_ADMIN%'", $params, $types);

        return $admins !== [] ? $admins : $this->addressesFrom($sql, $params, $types);
    }

    /**
     * @param list<mixed>          $params
     * @param list<ParameterType>  $types
     *
     * @return list<string>
     */
    private function addressesFrom(string $sql, array $params = [], array $types = []): array
    {
        try {
            $rows = $this->connection->fetchFirstColumn($sql, $params, $types);
        } catch (Throwable $e) {
            $this->logger->error('Verification notification could not read its recipients', [
                'exception' => $e->getMessage(),
            ]);

            return [];
        }

        $addresses = [];

        foreach ($rows as $row) {
            $address = trim((string) $row);

            if ($address !== '' && ! in_array($address, $addresses, true)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    private function basedIn(Company $company): string
    {
        $city = trim((string) $company->getCity());
        $country = trim((string) $company->getCountry());

        if ($city !== '' && $country !== '') {
            return $city . ', ' . $country;
        }

        return $city !== '' ? $city : $country;
    }

    /**
     * An absolute link, or a plain sentence when one cannot be built - a console
     * command has no host to build a URL from, and a broken link in the email
     * would be worse than none.
     */
    private function url(string $route, string $fallback): string
    {
        try {
            return $this->router->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (Throwable) {
            return $fallback;
        }
    }
}
