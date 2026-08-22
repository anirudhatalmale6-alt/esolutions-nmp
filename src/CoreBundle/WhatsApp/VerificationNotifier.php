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

use Psr\Log\LoggerInterface;
use SolidInvoice\CoreBundle\Platform;
use Throwable;
use function sprintf;
use function trim;

/**
 * Sends the "confirm your account" link over WhatsApp.
 *
 * WhatsApp is the preferred channel now - the activation emails were not
 * arriving - but it is the LESS reliable of the two: the gateway keeps a
 * session attached to a real phone, and that session lapses without warning.
 * So this is additional to the email, never instead of it, and it is
 * unconditionally safe to call: every failure is swallowed and logged, because
 * a person who has just typed their password must end up registered whatever
 * WhatsApp is doing this morning.
 */
final readonly class VerificationNotifier
{
    public function __construct(
        private WhatsAppSender $sender,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool whether the gateway accepted it - for logging and for the
     *              check page, never for deciding whether the signup worked
     */
    public function sendVerification(?string $number, string $signedUrl): bool
    {
        if ($number === null || trim($number) === '') {
            return false;
        }

        try {
            if (! $this->sender->isConfigured()) {
                return false;
            }

            $result = $this->sender->send($number, $this->message($signedUrl));
        } catch (Throwable $e) {
            // isConfigured() reads settings, which reads the database. Nothing
            // about a WhatsApp courtesy message justifies taking a signup down.
            $this->logger->error('WhatsApp verification could not be attempted', ['exception' => $e]);

            return false;
        }

        if (! $result->sent) {
            $this->logger->error('WhatsApp verification was not delivered', ['reason' => $result->detail]);
        }

        return $result->sent;
    }

    private function message(string $signedUrl): string
    {
        /*
         * The portal's own name, not a company's.
         *
         * This used to read system/company/company_name, and getPlatformWide()
         * answers with whichever business happened to fill that setting in - so
         * somebody signing up to one business could be welcomed to the name of
         * another. Whoever is joining, they are joining the platform, and the
         * platform has one name.
         */
        return sprintf(
            "Welcome to %s.\n\nTap this link to confirm your account and finish signing up:\n%s\n\n"
            . "If you did not sign up, ignore this message - nothing will happen without the link being opened.",
            Platform::NAME,
            $signedUrl,
        );
    }
}
