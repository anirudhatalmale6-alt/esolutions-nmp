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

namespace SolidInvoice\SettingsBundle\Action;

use SolidInvoice\CoreBundle\Platform;
use SolidInvoice\CoreBundle\WhatsApp\WhatsAppSender;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use function sprintf;
use function substr;
use function trim;

/**
 * Sends one WhatsApp message to a number the owner types, and says what
 * happened.
 *
 * Until this existed the only way to find out whether the gateway credentials
 * were right was to register a new account and wait to see if a code arrived -
 * which leaves a real account behind on a live system every time you check.
 *
 * The message body is fixed here on purpose. An admin box that sends arbitrary
 * text to an arbitrary number is a spam gun wearing a settings page; a test
 * button only has to answer one question, so it sends one sentence.
 */
final class WhatsAppTest extends AbstractController
{
    private const string MESSAGE = 'Test message from ' . Platform::NAME . '. If you are reading this, WhatsApp is set up correctly.';

    public function __construct(
        private readonly WhatsAppSender $whatsAppSender,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('whatsapp_test', $request->request->get('_csrf_token'))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $number = trim((string) $request->request->get('number'));

        if ($number === '') {
            return $this->back('error', 'Type the number to send the test to first.');
        }

        // Checked before sending, because the gateway accepts a mangled id and
        // then quietly delivers to nobody - which reads exactly like "the
        // credentials are wrong" and sends you looking in the wrong place.
        if (WhatsAppSender::chatId($number) === null) {
            return $this->back('error', sprintf(
                'Nothing was sent - %s is not a number WhatsApp can reach. Use the full international form with the country code, e.g. 971501234567.',
                $number,
            ));
        }

        if (! $this->whatsAppSender->isConfigured()) {
            return $this->back('error', 'Nothing was sent - fill in the instance ID and the API token, tick Enabled, and save, before testing.');
        }

        $result = $this->whatsAppSender->send($number, self::MESSAGE);

        if ($result->sent) {
            // Deliberately not "delivered". A 2xx means the gateway took the
            // message; whether WhatsApp then handed it to that phone is a
            // different question, and only the phone can answer it.
            return $this->back('success', sprintf(
                'The gateway accepted a test message for %s. Check that phone - if it does not arrive within a minute, the instance is linked to a number that cannot send.',
                $number,
            ));
        }

        return $this->back('error', sprintf(
            'The test failed. %s',
            // Bounded: an HTML error page from a wrong API address would
            // otherwise arrive on screen in full.
            substr($result->detail, 0, 400),
        ));
    }

    private function back(string $type, string $message): Response
    {
        $this->addFlash($type, $message);

        return $this->redirectToRoute('_settings', ['section' => 'whatsapp']);
    }
}
