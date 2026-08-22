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

namespace SolidInvoice\CoreBundle\Twig\Extension;

use Override;
use SolidInvoice\CoreBundle\WhatsApp\WhatsAppSender;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Can a page offer to send something over WhatsApp itself?
 *
 * The invoice and quote screens have two ways of reaching a customer: hand the
 * message to the user's OWN WhatsApp (a wa.me link - a person pressing send,
 * which WhatsApp never objects to), or push it through the gateway. The second
 * only exists when the owner has linked a gateway and switched it on, and a
 * button that is there when it cannot work is worse than no button.
 */
final class WhatsAppExtension extends AbstractExtension
{
    public function __construct(
        private readonly WhatsAppSender $sender,
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('whatsapp_gateway_ready', fn (): bool => $this->sender->isConfigured()),
            new TwigFunction('whatsapp_chat_id', static fn (?string $number): ?string => $number === null ? null : WhatsAppSender::chatId($number)),
        ];
    }
}
