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

namespace SolidInvoice\CoreBundle\Action\Marketplace;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use SolidInvoice\CoreBundle\Marketplace\MarketplaceManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use function base64_decode;
use function hex2bin;
use function in_array;
use function strtolower;

/**
 * One seller's Marketplace logo, as a file the browser can keep.
 *
 * These live in app_config as "type|base64" and used to be pasted straight into
 * the Marketplace grid as data: URIs - so every logo on the page was downloaded
 * inside the HTML, on every search, and could never be cached. The page got
 * heavier with every business that joined.
 *
 * The address carries a fingerprint of the image (?v=), so it can be cached
 * forever and still change the moment a seller uploads a new one.
 *
 * Open, like the page that shows them: /marketplace is public, so a visitor who
 * is not signed in must get the logos too - otherwise the Marketplace is a page
 * of broken images for exactly the people it is meant to attract. It needs its
 * own line in access_control; being reachable from a public page is not enough.
 * A trading business's own logo on its own listing is not private data.
 *
 * NOTE: the route deliberately does NOT end in .png. Web servers are configured
 * to serve image extensions straight off disk without ever calling PHP, so a
 * generated image on a path ending in .png answers 404.
 */
final readonly class SellerLogo
{
    /** A year. The address changes when the picture does, so this is safe. */
    private const int MAX_AGE = 31_536_000;

    /** Only what an <img> can actually render, and only what we store. */
    private const array ALLOWED_TYPES = ['png', 'jpeg', 'jpg', 'gif', 'webp', 'svg+xml'];

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(Request $request, string $company): Response
    {
        $binary = @hex2bin($company);

        if ($binary === false) {
            throw new NotFoundHttpException('No such logo');
        }

        try {
            // The seller's own Marketplace logo first, then the invoice logo -
            // the same order the grid itself uses. Raw SQL: this is read by
            // buyers from every other business, so it must not be scoped to the
            // company the request happens to be in.
            $raw = (string) $this->connection->fetchOne(
                "SELECT COALESCE(NULLIF(mpl.setting_value, ''), col.setting_value)
                 FROM companies c
                 LEFT JOIN app_config mpl ON mpl.company_id = c.id AND mpl.setting_key = 'marketplace/logo'
                 LEFT JOIN app_config col ON col.company_id = c.id AND col.setting_key = 'system/company/logo'
                 WHERE c.id = ?",
                [$binary],
                [ParameterType::BINARY],
            );
        } catch (Throwable) {
            throw new NotFoundHttpException('No such logo');
        }

        [$type, $data] = MarketplaceManager::splitStoredImage($raw);

        if ($type === '' || $data === '') {
            throw new NotFoundHttpException('No such logo');
        }

        $binaryImage = base64_decode($data, true);

        if ($binaryImage === false || $binaryImage === '') {
            throw new NotFoundHttpException('No such logo');
        }

        // Whatever is in that column is going out with a content type on it, so
        // it decides how a browser treats the bytes. Anything not on the list
        // is served as a download rather than rendered.
        $type = strtolower($type);
        $contentType = in_array($type, self::ALLOWED_TYPES, true)
            ? 'image/' . ($type === 'jpg' ? 'jpeg' : $type)
            : 'application/octet-stream';

        $response = new Response($binaryImage, Response::HTTP_OK, [
            'Content-Type' => $contentType,
            // An uploaded file's own bytes, so never let a browser sniff it into
            // something executable.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);

        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);
        $response->setEtag(MarketplaceManager::imageFingerprint($raw));

        // A 304 costs nothing and is the whole point of moving these out of the
        // page in the first place.
        $response->isNotModified($request);

        return $response;
    }
}
