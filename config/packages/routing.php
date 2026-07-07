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

use Symfony\Config\FrameworkConfig;
use function Symfony\Component\DependencyInjection\Loader\Configurator\env;

return static function (FrameworkConfig $config): void {
    $config
        ->router()
        ->utf8(true)
        ->defaultUri(env('SOLIDINVOICE_APPLICATION_URL'));

    // Trust reverse-proxy forwarded headers so generated URLs use the real
    // external host and scheme instead of the internal localhost. This makes
    // the app work behind Cloud Shell web preview, tunnels and production
    // reverse proxies without hardcoding the public URL.
    $config->trustedProxies('127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,169.254.0.0/16');
    $config->trustedHeaders([
        'x-forwarded-for',
        'x-forwarded-host',
        'x-forwarded-proto',
        'x-forwarded-port',
        'x-forwarded-prefix',
    ]);
};
