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

use SolidInvoice\CoreBundle\Marketplace\MediaStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Hands out a Marketplace picture - an advert, or a photo on a community post.
 *
 * Deliberately open, like the page that shows them: a buyer with no account has
 * to be able to see the stock, and an advert somebody paid for is worth nothing
 * behind a login.
 *
 * The path comes off the URL, so it is untrusted from end to end. It is never
 * used to build a filename directly - MediaStore checks it lands inside the
 * media folder and nowhere else, and anything that does not is simply a 404.
 */
final class Media extends AbstractController
{
    /** Random filenames, so a picture never changes - a year is safe. */
    private const int MAX_AGE = 31536000;

    public function __construct(
        private readonly MediaStore $store,
    ) {
    }

    public function __invoke(string $folder, string $file): Response
    {
        $absolute = $this->store->resolve($folder . '/' . $file);

        if ($absolute === null) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($absolute);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $file);

        // Everything served here is a re-encoded JPEG written by MediaStore, so
        // it is said outright rather than guessed at from the name.
        $response->headers->set('Content-Type', 'image/jpeg');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);
        $response->headers->addCacheControlDirective('immutable');

        return $response;
    }
}
