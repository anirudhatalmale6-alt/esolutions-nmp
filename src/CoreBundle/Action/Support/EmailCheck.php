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

namespace SolidInvoice\CoreBundle\Action\Support;

use JsonException;
use SolidInvoice\CoreBundle\Platform;
use SolidInvoice\MailerBundle\Factory\MailerConfigFactory;
use SolidInvoice\SettingsBundle\Repository\SettingsRepository;
use SolidInvoice\SettingsBundle\SystemConfig;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;
use function fclose;
use function fgets;
use function gethostbyname;
use function is_array;
use function json_decode;
use function ltrim;
use function openssl_x509_parse;
use function sprintf;
use function str_contains;
use function strcasecmp;
use function stream_context_create;
use function stream_context_get_params;
use function stream_set_timeout;
use function stream_socket_client;
use function strstr;
use function strtolower;
use function trim;
use const STREAM_CLIENT_CONNECT;

/**
 * Why an email did not arrive.
 *
 * Every send in this application is best-effort: a mail failure must never cost
 * somebody the invoice, the invite or the support reply they had just written,
 * so each one is wrapped and logged. The price of that is silence - the page
 * says it worked, the log says otherwise, and nobody reads the log.
 *
 * Worse, mail can fail with no error at all. SOLIDINVOICE_MAILER_DSN defaults to
 * null://null, so when no mail provider can be found the message is accepted and
 * thrown away, successfully.
 *
 * This page reports what is actually configured, which business it came from,
 * and will send one real email and print whatever came back.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class EmailCheck extends AbstractController
{
    /**
     * Short on purpose. This runs while an admin waits on a page, and a mail
     * server that has not answered in six seconds has told us what we need.
     */
    private const float PROBE_TIMEOUT = 6.0;

    public function __construct(
        private readonly SystemConfig $config,
        private readonly SettingsRepository $settings,
        private readonly MailerInterface $mailer,
        #[Autowire(env: 'SOLIDINVOICE_MAILER_DSN')]
        private readonly string $fallbackDsn = '',
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $result = null;
        $probe = null;
        $state = $this->state();

        if ($request->isMethod('POST')) {
            if ($request->request->get('action') === 'probe') {
                // Guarded like the send is: this opens an outbound connection,
                // so it is not something another site gets to trigger.
                if ($this->isCsrfTokenValid('email_check', (string) $request->request->get('_token'))) {
                    $probe = $this->probe($state['target']);
                }
            } else {
                $result = $this->sendTest($request);
            }
        }

        return $this->render('@SolidInvoiceCore/Support/email_check.html.twig', [
            'state' => $state,
            'result' => $result,
            'probe' => $probe,
            'myAddress' => $this->myAddress(),
        ]);
    }

    /**
     * @return array{provider: ?string, providerOwner: ?string, fromAddress: ?string,
     *               fromOwner: ?string, fallbackDsn: string, discarding: bool, error: ?string,
     *               loginAccount: ?string, overwritesFrom: bool, mismatch: bool,
     *               target: ?array{host: string, port: int, tls: bool}}
     */
    private function state(): array
    {
        $raw = $this->config->getPlatformWide(MailerConfigFactory::CONFIG_KEY);
        $provider = null;
        $error = null;
        $transportConfig = [];

        if ($raw !== null && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $provider = (string) ($decoded['provider'] ?? '');
                $transportConfig = is_array($decoded['config'] ?? null) ? $decoded['config'] : [];
            } catch (JsonException) {
                // Stored by the settings form, so this should not happen - but if
                // it has, saying so beats showing "no provider" and sending the
                // owner off to re-enter settings that are already there.
                $error = 'The saved mail settings could not be read. Open Settings, Email and save them again.';
            }
        }

        $fromAddress = $this->config->getPlatformWide('email/from_address');
        $loginAccount = $this->loginAccount($transportConfig);
        $overwritesFrom = $this->overwritesFrom($provider, $transportConfig);

        return [
            'provider' => $provider === '' ? null : $provider,
            'providerOwner' => $this->settings->platformValueOwner(MailerConfigFactory::CONFIG_KEY),
            'fromAddress' => $fromAddress === '' ? null : $fromAddress,
            'fromOwner' => $this->settings->platformValueOwner('email/from_address'),
            'fallbackDsn' => $this->fallbackDsn,
            // Nothing configured AND the fallback is the null transport: every
            // email on the portal is being accepted and thrown away right now.
            'discarding' => ($provider === null || $provider === '')
                && str_contains($this->fallbackDsn, 'null://'),
            'error' => $error,
            // The mailbox the portal signs in to. Reported because the From
            // address is not free: see overwritesFrom() below.
            'loginAccount' => $loginAccount,
            'overwritesFrom' => $overwritesFrom,
            'mismatch' => $loginAccount !== null
                && $fromAddress !== null && $fromAddress !== ''
                && str_contains($loginAccount, '@')
                && strcasecmp($loginAccount, $fromAddress) !== 0,
            // The machine and port the portal actually dials, so the connection
            // can be tested on its own - see probe().
            'target' => $this->target($provider, $transportConfig),
        ];
    }

    /**
     * The mail server this provider connects to, when it connects to one.
     *
     * Sendgrid, Postmark, Mailgun, Mailchimp and SES are called over HTTPS on
     * the ordinary web port, which is never blocked - there is nothing to test,
     * so they return null rather than a misleading result.
     *
     * @param array<string, mixed> $config
     * @return ?array{host: string, port: int, tls: bool}
     */
    private function target(?string $provider, array $config): ?array
    {
        if ($provider === 'Gmail') {
            // Fixed by Symfony's Gmail transport, not by anything on the form.
            return ['host' => 'smtp.gmail.com', 'port' => 465, 'tls' => true];
        }

        if ($provider !== 'SMTP') {
            return null;
        }

        $host = trim((string) ($config['host'] ?? ''));

        if ($host === '') {
            return null;
        }

        // The settings form leaves the port blank by default; SmtpConfigurator
        // fills in 25, so that is what would really be dialled.
        $port = (int) ($config['port'] ?? 0);
        $port = $port > 0 ? $port : 25;

        // 465 is TLS from the first byte. 25 and 587 open in the clear and are
        // upgraded afterwards, so on those the greeting line is what identifies
        // the server.
        return ['host' => $host, 'port' => $port, 'tls' => $port === 465];
    }

    /**
     * Who actually answers on the mail server's address and port.
     *
     * Shared hosting very often intercepts outbound mail: the port is redirected
     * to the hosting company's own mail server, or their DNS answers for the
     * provider's hostname. Symfony reports that as a certificate name mismatch,
     * which reads like a fault in the portal and is not one - the credentials
     * are never even offered.
     *
     * This proves it either way by naming whoever picked up. It sends no
     * credentials and no email; it opens a connection, reads who is there, and
     * hangs up.
     *
     * @param ?array{host: string, port: int, tls: bool} $target
     * @return array{host: string, port: int, ip: ?string, reached: bool,
     *               identity: ?string, intercepted: bool, detail: string}
     */
    private function probe(?array $target): array
    {
        if ($target === null) {
            return [
                'host' => '', 'port' => 0, 'ip' => null, 'reached' => false, 'identity' => null,
                'intercepted' => false,
                'detail' => 'There is no mail server to test - this provider is called over the web, not over a mail connection.',
            ];
        }

        $host = $target['host'];
        $port = $target['port'];

        $ip = gethostbyname($host);
        // gethostbyname hands the name straight back when it cannot resolve it.
        $ip = ($ip === $host) ? null : $ip;

        $identity = null;
        $errorNumber = 0;
        $errorMessage = '';

        // Verification is deliberately off. A wrong certificate is the very
        // thing being looked for, and a verifying connection would refuse and
        // throw it away instead of letting us read the name off it.
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);

        $scheme = $target['tls'] ? 'ssl' : 'tcp';
        $socket = @stream_socket_client(
            sprintf('%s://%s:%d', $scheme, $host, $port),
            $errorNumber,
            $errorMessage,
            self::PROBE_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            return [
                'host' => $host,
                'port' => $port,
                'ip' => $ip,
                'reached' => false,
                'identity' => null,
                'intercepted' => false,
                'detail' => $errorMessage !== ''
                    ? $errorMessage
                    : 'The connection could not be opened, and no reason was given.',
            ];
        }

        if ($target['tls']) {
            $params = stream_context_get_params($socket);
            $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
            $parsed = $certificate !== null ? openssl_x509_parse($certificate) : false;

            if (is_array($parsed)) {
                $name = trim((string) ($parsed['subject']['CN'] ?? ''));
                $identity = $name === '' ? null : $name;
            }
        } else {
            stream_set_timeout($socket, (int) self::PROBE_TIMEOUT);
            $greeting = trim((string) fgets($socket, 512));
            $identity = $greeting === '' ? null : $greeting;
        }

        fclose($socket);

        return [
            'host' => $host,
            'port' => $port,
            'ip' => $ip,
            'reached' => true,
            'identity' => $identity,
            'intercepted' => $this->intercepted($host, $identity),
            'detail' => $target['tls']
                ? 'The name below is the one on the security certificate of whoever answered.'
                : 'The line below is the greeting sent back by whoever answered.',
        ];
    }

    /**
     * Whether the machine that answered is somebody other than the mail server
     * that was asked for.
     *
     * Only ever true when something identified itself AND that identity has
     * nothing to do with the host - staying quiet when unsure, because telling
     * an owner their host is tampering with their mail when it is not would
     * send them to an argument they cannot win.
     */
    private function intercepted(string $host, ?string $identity): bool
    {
        if ($identity === null) {
            return false;
        }

        $identity = strtolower($identity);
        $host = strtolower($host);

        if (str_contains($identity, $host)) {
            return false;
        }

        // Google answers on several names, and the certificate is a wildcard.
        if (str_contains($host, 'gmail.com') || str_contains($host, 'google')) {
            return ! str_contains($identity, 'google') && ! str_contains($identity, 'gmail');
        }

        // A certificate for the parent domain of the host it was asked for is
        // that host's own server, not an impostor: mail.example.com answering
        // with *.example.com is normal.
        $parent = strstr($host, '.');

        return ! ($parent !== false && $parent !== '' && str_contains($identity, ltrim($parent, '.')));
    }

    /**
     * The mailbox the portal signs in to, when the provider signs in as one.
     *
     * Never the password - only whether a login exists and which one. Key-based
     * providers (Sendgrid, Postmark, Mailgun, SES) have no mailbox at all; they
     * authorise a whole domain, so there is nothing to report and nothing that
     * conflicts with the From address.
     *
     * @param array<string, mixed> $config
     */
    private function loginAccount(array $config): ?string
    {
        // 'username' is what the Gmail form stores, 'user' is the SMTP form's.
        $account = trim((string) ($config['username'] ?? $config['user'] ?? ''));

        return $account === '' ? null : $account;
    }

    /**
     * Whether this provider will replace the From address with the account it
     * signed in as, whatever the settings say.
     *
     * Gmail does. It will not relay a message claiming to be from an address the
     * signed-in account does not own - it silently rewrites the header instead -
     * so changing "Sends from" to a second Gmail address achieves nothing on its
     * own. The portal has to sign in AS that address, with an app password
     * generated on that account. Nothing in the settings screen says so, and the
     * send does not fail, so the owner is left changing a field that is being
     * ignored and has no way to see it.
     *
     * @param array<string, mixed> $config
     */
    private function overwritesFrom(?string $provider, array $config): bool
    {
        if ($provider === 'Gmail') {
            return true;
        }

        // The same mailbox reached the long way round, via the SMTP option.
        $host = strtolower(trim((string) ($config['host'] ?? '')));

        return $provider === 'SMTP'
            && ($host === 'smtp.gmail.com' || $host === 'smtp.googlemail.com');
    }

    /**
     * Plain English for the failures that are not the portal's fault, so an
     * owner reading a stack trace knows who to go to.
     *
     * Deliberately narrow. A wrong guess here sends somebody to argue with
     * their hosting company over a password they typed in wrongly, so anything
     * not recognised gets no hint at all and the raw error stands on its own.
     */
    private function explain(string $error): ?string
    {
        $error = strtolower($error);

        if (str_contains($error, 'did not match expected cn') || str_contains($error, 'peer certificate')) {
            return 'That is not a problem with your email address or your password - the portal never got as far as offering them. It opened a connection to the mail server and somebody else answered, presenting their own security certificate. That is almost always the hosting company redirecting outgoing mail to their own server. Use "Who answers" below to see exactly who is picking up, then send that to your host and ask them to stop blocking outbound SMTP - or ask them for their own mail server details to use instead.';
        }

        if (str_contains($error, 'username and password not accepted') || str_contains($error, 'authentication failed') || str_contains($error, '535')) {
            return 'The mail server was reached, but it refused the sign-in. For Gmail this means the app password is wrong, has been revoked, or belongs to a different account than the one in the sending options. Generate a fresh app password on the same account and paste it in with no spaces.';
        }

        if (str_contains($error, 'connection could not be established') || str_contains($error, 'connection timed out') || str_contains($error, 'connection refused')) {
            return 'The mail server never answered at all. Outgoing mail is usually blocked by the hosting company when this happens. Use "Who answers" below to confirm it, then ask your host to allow outbound SMTP.';
        }

        return null;
    }

    /**
     * @return array{ok: bool, address: string, message: string, hint: ?string}
     */
    private function sendTest(Request $request): array
    {
        $address = trim((string) $request->request->get('address', ''));

        if ($address === '') {
            $address = $this->myAddress();
        }

        if (! $this->isCsrfTokenValid('email_check', (string) $request->request->get('_token'))) {
            return ['ok' => false, 'address' => $address, 'message' => 'That form had expired - please try again.', 'hint' => null];
        }

        if ($address === '' || ! str_contains($address, '@')) {
            return ['ok' => false, 'address' => $address, 'message' => 'Please give an address to send the test to.', 'hint' => null];
        }

        try {
            $email = (new Email())
                ->to(Address::create($address))
                ->subject(Platform::NAME . ' test email')
                ->text(
                    'This is a test from the Email Check page on ' . Platform::NAME . ".\n\n"
                    . "If you are reading it, the portal can send email.\n"
                );

            // From is filled in by EmailFromListener from the mail settings.
            $this->mailer->send($email);
        } catch (Throwable $e) {
            // The whole point of this page: show what actually came back, not a
            // tidied-up version of it. The hint sits alongside it, never in
            // place of it.
            return [
                'ok' => false,
                'address' => $address,
                'message' => sprintf('%s: %s', $e::class, $e->getMessage()),
                'hint' => $this->explain($e->getMessage()),
            ];
        }

        return [
            'ok' => true,
            'address' => $address,
            'message' => 'The mail server accepted it without an error. If it does not arrive, check the spam folder - after that it is the mail provider, not the portal.',
            'hint' => null,
        ];
    }

    private function myAddress(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? (string) $user->getEmail() : '';
    }
}
