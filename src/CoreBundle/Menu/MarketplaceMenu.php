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

namespace SolidInvoice\CoreBundle\Menu;

use Knp\Menu\ItemInterface;
use SolidWorx\Platform\PlatformBundle\Attributes\Menu\MenuBuilder;

final class MarketplaceMenu
{
    // Premium section, just above "Online store" at the bottom of the sidebar
    // (priority 6 > store's 5, < system's 10). Any signed-in user sees it; the
    // gold crown marks it as a premium feature (styling in Layout/base.html.twig).
    #[MenuBuilder(name: 'sidebar', priority: 6, role: 'ROLE_USER')]
    public function sidebar(ItemInterface $menu): void
    {
        $marketplace = $menu->addChild('marketplace', [
            'label' => 'Marketplace',
            'attributes' => [
                'class' => 'premium-feature',
            ],
            'extras' => [
                'icon' => 'crown',
            ],
        ]);

        // Searching the marketplace is open to every signed-in user.
        $marketplace->addChild('marketplace.search', [
            'route' => '_marketplace',
            'label' => 'Search',
            'extras' => [
                'icon' => 'search',
            ],
        ]);

        // Sharing your own stock onto the marketplace is the paid/premium side -
        // for now limited to the business owner (Admin+); a subscription gate will
        // layer on top of this in the next phase.
        $marketplace->addChild('marketplace.share', [
            'route' => '_marketplace_settings',
            'label' => 'Share My Inventory',
            'extras' => [
                'icon' => 'building-store',
                'role' => 'ROLE_ADMIN',
            ],
        ]);
    }
}
