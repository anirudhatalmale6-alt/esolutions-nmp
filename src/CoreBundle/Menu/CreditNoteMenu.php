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

final class CreditNoteMenu
{
    #[MenuBuilder(name: 'sidebar', priority: 42, role: 'ROLE_MANAGER')]
    public function sidebar(ItemInterface $menu): void
    {
        $menu->addChild('credit_notes', [
            'route' => '_credit_notes_list',
            'label' => 'Refund / Credit',
            'extras' => [
                'icon' => 'receipt-refund',
            ],
        ]);
    }
}
