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

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Never fetch an icon over the internet while somebody is waiting for a page.
 *
 * ux-icons ships with iconify "on demand": the first time a page asks for an
 * icon it is downloaded from api.iconify.design and kept in the Symfony cache.
 * That is a fine default for a laptop and a bad one here, for two reasons:
 *
 *   1. deploy.sh rebuilds the cache, so EVERY update empties the icon store.
 *      The next visitor's page load then becomes a queue of HTTPS calls to a
 *      third-party API, one after another, before a single byte is sent back.
 *   2. When one of those calls is slow, the page does not lose an icon - it
 *      dies. The client's log caught it exactly:
 *
 *        TimeoutException: Idle timeout reached for
 *        "https://api.iconify.design/tabler.json?icons=building-store"
 *
 *      That is the "slow, then Error 500, then fine after a few refreshes"
 *      he reported. Fine after a few refreshes because each page that did
 *      load put a few more icons in the cache.
 *
 * Every icon the app asks for is now committed under assets/icons, so:
 *
 *   on_demand: false     removes the remote registry from the container
 *                        altogether - no HTTP call is *possible* during a
 *                        render, rather than merely unlikely.
 *   ignore_not_found     an icon nobody vendored renders as nothing instead of
 *                        taking the page down with it. A missing picture is a
 *                        blemish; a 500 is a business offline.
 *
 * The trade in that second one is that a missing icon is now silent, so it has
 * to be caught before it ships: icons_are_vendored.php walks every tabler: name
 * in the codebase and fails if any of them has no file.
 */
return static function (ContainerConfigurator $container): void {
    $container->extension('ux_icons', [
        'iconify' => [
            'on_demand' => false,
        ],
        'ignore_not_found' => true,
    ]);
};
