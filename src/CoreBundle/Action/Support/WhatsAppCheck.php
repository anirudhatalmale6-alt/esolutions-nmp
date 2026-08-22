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

use SolidInvoice\CoreBundle\Platform;
use SolidInvoice\CoreBundle\WhatsApp\WhatsAppSender;
use SolidInvoice\SettingsBundle\Repository\SettingsRepository;
use SolidInvoice\SettingsBundle\SystemConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function sprintf;
use function trim;

/**
 * Whether the WhatsApp gateway is actually live, and proof either way.
 *
 * The gateway keeps a session linked to a real phone. Sessions lapse, numbers
 * get banned, and when that happens the portal carries on looking fine - the
 * verification simply goes out by email instead and nobody notices the WhatsApp
 * half died. So the owner needs a page that sends one real message on demand and
 * prints whatever the gateway said back, including the failures.
 *
 * The settings this reads are on the Settings page, WhatsApp tab.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class WhatsAppCheck extends AbstractController
{
    public function __construct(
        private readonly WhatsAppSender $sender,
        private readonly SystemConfig $config,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $result = null;

        if ($request->isMethod('POST')) {
            $result = $this->sendTest($request);
        }

        return $this->render('@SolidInvoiceCore/Support/whatsapp_check.html.twig', [
            'state' => $this->state(),
            'result' => $result,
        ]);
    }

    /**
     * @return array{configured: bool, enabled: bool, instanceId: ?string, hasToken: bool,
     *               senderNumber: ?string, apiUrl: ?string, owner: ?string}
     */
    private function state(): array
    {
        $instanceId = $this->value('whatsapp/instance_id');

        return [
            'configured' => $this->sender->isConfigured(),
            'enabled' => $this->config->getPlatformWide('whatsapp/enabled') === '1',
            'instanceId' => $instanceId,
            // Never rendered, only reported as present or absent. A token on a
            // screen gets photographed, pasted into chats and shoulder-read.
            'hasToken' => $this->value('whatsapp/api_token') !== null,
            'senderNumber' => $this->value('whatsapp/sender_number'),
            'apiUrl' => $this->value('whatsapp/api_url'),
            // Which business's settings are in force, so a value saved on the
            // wrong account is visible rather than baffling.
            'owner' => $this->settings->platformValueOwner('whatsapp/instance_id'),
        ];
    }

    /**
     * @return array{ok: bool, number: string, message: string}
     */
    private function sendTest(Request $request): array
    {
        $number = trim((string) $request->request->get('number', ''));

        if (! $this->isCsrfTokenValid('whatsapp_check', (string) $request->request->get('_token'))) {
            return ['ok' => false, 'number' => $number, 'message' => 'That form had expired - please try again.'];
        }

        if ($number === '') {
            return ['ok' => false, 'number' => $number, 'message' => 'Please give a number to send the test to, in full international form.'];
        }

        $chatId = WhatsAppSender::chatId($number);

        if ($chatId === null) {
            return [
                'ok' => false,
                'number' => $number,
                'message' => 'That does not look like a full international number. Enter it with the country code and no plus, for example 971501234567.',
            ];
        }

        $result = $this->sender->send($number, 'This is a test message from ' . Platform::NAME . '. If you are reading it, the portal can send WhatsApp.');

        return [
            'ok' => $result->sent,
            'number' => $number,
            'message' => $result->sent
                ? sprintf('The gateway accepted it for %s. If it does not arrive, the session is linked but the number could not be reached - check that this number has WhatsApp, and that your plan allows sending to people who have not saved you.', $chatId)
                : $result->detail,
        ];
    }

    /**
     * A setting the owner deliberately filled in, or null.
     */
    private function value(string $key): ?string
    {
        $value = trim($this->config->getPlatformWide($key) ?? '');

        return $value === '' ? null : $value;
    }
}
