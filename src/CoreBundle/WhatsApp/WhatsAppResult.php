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

/**
 * What happened when we tried to send. Deliberately not an exception: a lapsed
 * WhatsApp session is an ordinary Tuesday, not an error the request should die
 * on, and the reason has to survive as far as the page that can show it.
 */
final readonly class WhatsAppResult
{
    /**
     * Public, not private, purely so this looks exactly like the value objects
     * already in the container's autowiring glob (SettingsBundle's Config DTO).
     * Those are registered as services and then dropped again as unused; a
     * constructor the container cannot call is a shape this codebase has never
     * proven. Build these with success() and failure() all the same.
     */
    public function __construct(
        public bool $sent,
        public string $detail,
    ) {
    }

    public static function success(string $detail = ''): self
    {
        return new self(true, $detail);
    }

    public static function failure(string $detail): self
    {
        return new self(false, $detail);
    }
}
