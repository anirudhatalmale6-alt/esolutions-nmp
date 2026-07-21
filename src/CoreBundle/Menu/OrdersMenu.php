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

final class OrdersMenu
{
    // Shown to the order team (ROLE_ORDERS) and everyone above them. Priority
    // just above the store so "Orders" sits next to "Online store" at the
    // bottom of the sidebar.
    #[MenuBuilder(name: 'sidebar', priority: 6, role: 'ROLE_ORDERS')]
    public function sidebar(ItemInterface $menu): void
    {
        $menu->addChild('orders', [
            'route' => '_orders_list',
            'label' => 'Orders',
            'extras' => [
                'icon' => 'package',
            ],
        ]);
    }
}
