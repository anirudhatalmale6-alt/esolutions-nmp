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

use Symfony\Component\Routing\Exception\InvalidParameterException;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use function explode;
use function count;

/**
 * Turns a stored Marketplace picture path into a URL.
 *
 * A one-line function rather than splitting the path in the template: the stored
 * form is "folder/file", and a template that takes it apart itself breaks
 * quietly - a path that is missing or malformed becomes a broken image on a page
 * a buyer is looking at, instead of nothing at all.
 */
final class MarketplaceExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('marketplace_media', $this->url(...)),
        ];
    }

    /**
     * The URL for a stored picture, or an empty string when there is nothing to
     * show - which templates test for, so an advert with no picture draws no
     * broken image.
     */
    public function url(?string $path): string
    {
        if ($path === null || $path === '') {
            return '';
        }

        $parts = explode('/', $path);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return '';
        }

        // The route pins down what a folder and a filename may look like. A row
        // that does not fit - written by hand, left over from something older -
        // must not take the whole public page down with it.
        try {
            return $this->router->generate('_marketplace_media', [
                'folder' => $parts[0],
                'file' => $parts[1],
            ]);
        } catch (InvalidParameterException | MissingMandatoryParametersException | RouteNotFoundException) {
            return '';
        }
    }
}
