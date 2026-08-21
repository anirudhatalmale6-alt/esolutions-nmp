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

use Symfony\Config\MonologConfig;

return static function (MonologConfig $config): void {
    $config
        ->handler('main')
        ->type('fingers_crossed')
        ->actionLevel('error')
        ->handler('nested')
        ->bufferSize(50)
        ->excludedHttpCode(404)
        ->excludedHttpCode(405);

    $config
        ->handler('nested')
        ->type('stream')
        ->path('php://stderr')
        ->level('debug')
        ->formatter('monolog.formatter.json')
    ;

    /*
     * The same errors again, but into a file we can actually read.
     *
     * php://stderr is the right default for a container, where the platform
     * collects stderr and shows it to you. On shared hosting under PHP-FPM it
     * goes to the FPM master's stderr, which on this host is collected by
     * nobody: not one application exception from the last six weeks reached
     * ~/logs/php.error.log (that file only ever gets PHP's own fatals and
     * deprecations, which bypass Monolog entirely).
     *
     * That is why every 500 so far has had to be diagnosed by reasoning from
     * the symptom instead of read off a stack trace. This handler ends that.
     * It writes to var/log/error.log, is opened only when something actually
     * goes wrong, and carries the stack traces.
     */
    $config
        ->handler('file')
        ->type('fingers_crossed')
        ->actionLevel('error')
        ->handler('file_nested')
        ->bufferSize(30)
        ->excludedHttpCode(404)
        ->excludedHttpCode(405);

    $config
        ->handler('file_nested')
        ->type('stream')
        ->path('%kernel.logs_dir%/error.log')
        ->level('debug')
        ->includeStacktraces(true);

    $config
        ->handler('console')
        ->type('console')
        ->processPsr3Messages(false)
        ->channels()
        ->elements(['!event', '!doctrine']);

    /*
     @TODO: Only enable deprecation logging for specific scenarios
     $config
        ->handler('deprecation')
        ->type('stream')
        ->path('php://stderr')
        ->channels()
        ->elements(['deprecation']);*/
};
