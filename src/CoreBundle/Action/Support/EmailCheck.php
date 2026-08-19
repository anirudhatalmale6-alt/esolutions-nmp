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
use function json_decode;
use function sprintf;
use function str_contains;
use function trim;

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

        if ($request->isMethod('POST')) {
            $result = $this->sendTest($request);
        }

        return $this->render('@SolidInvoiceCore/Support/email_check.html.twig', [
            'state' => $this->state(),
            'result' => $result,
            'myAddress' => $this->myAddress(),
        ]);
    }

    /**
     * @return array{provider: ?string, providerOwner: ?string, fromAddress: ?string,
     *               fromOwner: ?string, fallbackDsn: string, discarding: bool, error: ?string}
     */
    private function state(): array
    {
        $raw = $this->config->getPlatformWide(MailerConfigFactory::CONFIG_KEY);
        $provider = null;
        $error = null;

        if ($raw !== null && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $provider = (string) ($decoded['provider'] ?? '');
            } catch (JsonException) {
                // Stored by the settings form, so this should not happen - but if
                // it has, saying so beats showing "no provider" and sending the
                // owner off to re-enter settings that are already there.
                $error = 'The saved mail settings could not be read. Open Settings, Email and save them again.';
            }
        }

        $fromAddress = $this->config->getPlatformWide('email/from_address');

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
        ];
    }

    /**
     * @return array{ok: bool, address: string, message: string}
     */
    private function sendTest(Request $request): array
    {
        $address = trim((string) $request->request->get('address', ''));

        if ($address === '') {
            $address = $this->myAddress();
        }

        if (! $this->isCsrfTokenValid('email_check', (string) $request->request->get('_token'))) {
            return ['ok' => false, 'address' => $address, 'message' => 'That form had expired - please try again.'];
        }

        if ($address === '' || ! str_contains($address, '@')) {
            return ['ok' => false, 'address' => $address, 'message' => 'Please give an address to send the test to.'];
        }

        try {
            $email = (new Email())
                ->to(Address::create($address))
                ->subject('B2B Network test email')
                ->text(
                    "This is a test from the Email Check page on B2B Network.\n\n"
                    . "If you are reading it, the portal can send email.\n"
                );

            // From is filled in by EmailFromListener from the mail settings.
            $this->mailer->send($email);
        } catch (Throwable $e) {
            // The whole point of this page: show what actually came back, not a
            // tidied-up version of it.
            return [
                'ok' => false,
                'address' => $address,
                'message' => sprintf('%s: %s', $e::class, $e->getMessage()),
            ];
        }

        return [
            'ok' => true,
            'address' => $address,
            'message' => 'The mail server accepted it without an error. If it does not arrive, check the spam folder - after that it is the mail provider, not the portal.',
        ];
    }

    private function myAddress(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? (string) $user->getEmail() : '';
    }
}
