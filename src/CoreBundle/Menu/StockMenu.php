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

final class StockMenu
{
    #[MenuBuilder(name: 'sidebar', priority: 40)]
    public function sidebar(ItemInterface $menu): void
    {
        $menu->addChild('stock', [
            'route' => '_stock_list',
            'label' => 'Stock',
            'extras' => [
                'icon' => 'package',
            ],
        ]);
    }
}
