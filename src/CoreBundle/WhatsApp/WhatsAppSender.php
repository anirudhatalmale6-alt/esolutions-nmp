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

namespace SolidInvoice\CoreBundle\WhatsApp;

use SolidInvoice\CoreBundle\Config\WhatsAppConfigProvider;
use SolidInvoice\SettingsBundle\SystemConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;
use function ltrim;
use function preg_replace;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * Sends a WhatsApp message through the linked gateway.
 *
 * The gateway is an unofficial one: it keeps a WhatsApp session attached to a
 * real number. That session expires and the number can be banned, so EVERY
 * failure here is expected and ordinary. Nothing this class does may throw into
 * a request - a signup must not fail because a WhatsApp session lapsed
 * overnight. Callers get a {@see WhatsAppResult} and decide what to do; the
 * verification flow falls back to email.
 */
final readonly class WhatsAppSender
{
    /**
     * Long enough that a slow gateway does not hold a signup open, short enough
     * that the caller's email fallback still runs inside the same request.
     */
    private const int TIMEOUT_SECONDS = 8;

    public function __construct(
        private SystemConfig $config,
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Whether the owner has both switched this on and filled in an instance.
     *
     * Checked separately from sending so callers can decide up front whether to
     * ask a member for a number at all.
     */
    public function isConfigured(): bool
    {
        return $this->config->getPlatformWide('whatsapp/enabled') === '1'
            && $this->instanceId() !== ''
            && $this->apiToken() !== '';
    }

    public function send(string $number, string $message): WhatsAppResult
    {
        if (! $this->isConfigured()) {
            return WhatsAppResult::failure('WhatsApp is switched off, or the instance ID and token have not been filled in.');
        }

        $chatId = self::chatId($number);

        if ($chatId === null) {
            return WhatsAppResult::failure(sprintf('"%s" is not a number WhatsApp can be reached on.', $number));
        }

        $url = sprintf(
            '%s/waInstance%s/sendMessage/%s',
            $this->apiUrl(),
            $this->instanceId(),
            $this->apiToken(),
        );

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => [
                    'chatId' => $chatId,
                    'message' => $message,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $status = $response->getStatusCode();
            // false: a 4xx/5xx body is the useful part (an expired instance says
            // so in it), and throwing would lose it.
            $body = trim($response->getContent(false));
        } catch (Throwable $e) {
            return WhatsAppResult::failure(sprintf('%s: %s', $e::class, $e->getMessage()));
        }

        if ($status >= 200 && $status < 300) {
            return WhatsAppResult::success($body);
        }

        return WhatsAppResult::failure(sprintf(
            'The gateway answered %d. %s',
            $status,
            $body === '' ? 'It sent no explanation.' : substr($body, 0, 300),
        ));
    }

    /**
     * A WhatsApp chat id is the number in full international form, digits only,
     * with the service's suffix - no plus, no spaces, no leading zero.
     *
     * Returns null rather than guessing when the number is too short to be a
     * real one: sending to a mangled id fails silently at the far end, which
     * looks exactly like "the member never replied".
     */
    public static function chatId(string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        // 00 is the other way of writing the leading plus.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $digits = ltrim($digits, '0');

        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return $digits . '@c.us';
    }

    /**
     * Do these two reach the same phone?
     *
     * "Written the same" and "the same number" are different questions:
     * +971 50 123 4567, 00971501234567 and 971501234567 are three strings and
     * one telephone. Answered by comparing what a message would actually be
     * addressed to, so nothing can call two numbers the same that the gateway
     * would send to separately.
     *
     * A number that cannot be addressed at all is not the same as anything,
     * including another unaddressable one - two different typos are not two
     * people sharing a phone.
     */
    public static function isSameNumber(string $a, string $b): bool
    {
        $left = self::chatId($a);

        return $left !== null && $left === self::chatId($b);
    }

    private function apiUrl(): string
    {
        // A setting still holding the value it shipped with is not a choice, so
        // getPlatformWide() reports null for it - see SettingsRepository. That
        // is right for a token and wrong for this, which has a working default.
        $url = $this->config->getPlatformWide('whatsapp/api_url');

        return rtrim($url !== null && trim($url) !== '' ? trim($url) : WhatsAppConfigProvider::DEFAULT_API_URL, '/');
    }

    private function instanceId(): string
    {
        return trim($this->config->getPlatformWide('whatsapp/instance_id') ?? '');
    }

    private function apiToken(): string
    {
        return trim($this->config->getPlatformWide('whatsapp/api_token') ?? '');
    }
}
