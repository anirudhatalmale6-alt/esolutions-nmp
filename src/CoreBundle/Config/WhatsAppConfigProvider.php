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

namespace SolidInvoice\CoreBundle\Config;

use SolidInvoice\SettingsBundle\Config\ProviderInterface;
use SolidInvoice\SettingsBundle\DTO\Config;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * The WhatsApp gateway the portal sends verification codes through.
 *
 * These are PLATFORM-wide, not per-business: there is one linked WhatsApp
 * number for the whole portal. They live in app_config like everything else
 * (so the owner can edit them on the Settings page without a deploy), and are
 * read back with {@see \SolidInvoice\SettingsBundle\SystemConfig::getPlatformWide()},
 * which takes whichever business actually filled them in.
 *
 * The gateway is an unofficial one (Green API style): it keeps a WhatsApp
 * session linked to a real number. That session expires, and the number can be
 * banned, so the owner needs to be able to paste a new instance in at any time
 * without waiting for me - which is the whole reason these are settings and not
 * environment variables.
 */
final class WhatsAppConfigProvider implements ProviderInterface
{
    /**
     * The gateway's own address. Kept editable because these providers move
     * hosts, and because a replacement gateway with the same request shape can
     * then be dropped in without a code change.
     */
    public const string DEFAULT_API_URL = 'https://api.green-api.com';

    /**
     * @return Config[]
     */
    public function provide(array $data): array
    {
        return [
            new Config(
                'whatsapp/enabled',
                '0',
                'Send verification codes over WhatsApp. Leave off to send by email only.',
                CheckboxType::class,
            ),
            new Config(
                'whatsapp/instance_id',
                '',
                'The instance ID from your WhatsApp gateway account.',
                TextType::class,
            ),
            new Config(
                'whatsapp/api_token',
                '',
                'The API token for that instance. Replace it here whenever you re-link or change number.',
                TextType::class,
            ),
            new Config(
                'whatsapp/sender_number',
                '',
                'The number currently linked to the gateway, in full international form, e.g. 447700900123. Shown to you only, so you know which SIM is live.',
                TextType::class,
            ),
            new Config(
                'whatsapp/api_url',
                self::DEFAULT_API_URL,
                'Only change this if your gateway tells you to.',
                TextType::class,
            ),
        ];
    }
}
