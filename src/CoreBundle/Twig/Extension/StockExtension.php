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
use SolidInvoice\CoreBundle\Entity\StockMovement;
use SolidInvoice\CoreBundle\Stock\StockPoster;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Links a stock movement back to the document that caused it.
 *
 * The whole point of the history is being able to ask "why did this go out?"
 * and land on the invoice that took it, rather than reading a reference number
 * and going to look for it by hand.
 */
final class StockExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('stock_movement_link', fn (StockMovement $movement): ?string => $this->link($movement)),
        ];
    }

    private function link(StockMovement $movement): ?string
    {
        $id = $movement->getSourceId();

        if ($id === null || $id === '') {
            return null;
        }

        [$route, $parameter] = match ($movement->getSourceType()) {
            StockPoster::SOURCE_INVOICE => ['_invoices_view', 'id'],
            StockPoster::SOURCE_PURCHASE => ['_purchase_view', 'id'],
            // A credit note has no page of its own; the list is where it is
            // found, so send the user there rather than nowhere.
            StockPoster::SOURCE_CREDIT_NOTE => ['_credit_notes_list', null],
            default => [null, null],
        };

        if ($route === null) {
            return null;
        }

        try {
            return $this->urlGenerator->generate($route, $parameter === null ? [] : [$parameter => $id]);
        } catch (Throwable) {
            // A document that has since been deleted, or a route that moved:
            // show the reference as plain text instead of breaking the page.
            return null;
        }
    }
}
