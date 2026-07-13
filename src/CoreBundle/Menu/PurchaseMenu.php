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

final class PurchaseMenu
{
    #[MenuBuilder(name: 'sidebar', priority: 44)]
    public function sidebar(ItemInterface $menu): void
    {
        $menu->addChild('purchases', [
            'route' => '_purchases_list',
            'label' => 'Purchases',
            'extras' => [
                'icon' => 'shopping-cart',
            ],
        ]);
    }
}
