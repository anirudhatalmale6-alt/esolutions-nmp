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
    // Sits just below the dashboard so the model search is easy to reach for any
    // signed-in portal user. The page itself is public; this is the in-portal way in.
    #[MenuBuilder(name: 'sidebar', priority: 95, role: 'ROLE_USER')]
    public function sidebar(ItemInterface $menu): void
    {
        $menu->addChild('menu.top.marketplace', [
            'route' => '_marketplace',
            'extras' => ['icon' => 'building-store'],
        ]);
    }
}
