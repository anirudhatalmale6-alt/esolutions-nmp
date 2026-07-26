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

use SolidInvoice\CoreBundle\Marketplace\MarketplaceManager;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use function array_map;
use function array_merge;
use function trim;

/**
 * Public Marketplace search. A buyer types a phone model and sees which listed
 * businesses have it, straight from their Tally stock.
 *
 * Anyone may search. Guests see the same per-seller results as signed-in users -
 * location, model and quantity - only the business name is hidden and the Chat
 * button leads to login instead of WhatsApp. Signed-in portal users see the
 * business and a working "Chat Now" button. The page is text-only and
 * self-contained.
 */
final class Search
{
    public function __construct(
        private readonly MarketplaceManager $marketplace,
        private readonly Security $security,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[Template('@SolidInvoiceCore/Marketplace/search.html.twig')]
    public function __invoke(Request $request): array
    {
        $query = trim((string) $request->query->get('q', ''));
        $loggedIn = $this->security->isGranted('IS_AUTHENTICATED_REMEMBERED');

        // One listing per vendor, each with the list of models they hold.
        $rows = $query !== '' ? $this->marketplace->searchGrouped($query) : [];

        // Guests see the same listings but with the seller's identity and number
        // stripped out server-side, so nothing private ever reaches the browser.
        if (! $loggedIn) {
            $rows = array_map(
                static fn (array $r): array => array_merge($r, ['business' => '', 'whatsapp' => '', 'chatUrl' => '', 'logo' => '']),
                $rows
            );
        }

        return [
            'query' => $query,
            'searched' => $query !== '',
            'loggedIn' => $loggedIn,
            'results' => $rows,
        ];
    }
}
