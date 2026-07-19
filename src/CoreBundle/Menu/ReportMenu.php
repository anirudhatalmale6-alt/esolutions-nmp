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

final class ReportMenu
{
    #[MenuBuilder(name: 'sidebar', priority: 42, role: 'ROLE_ACCOUNTANT')]
    public function sidebar(ItemInterface $menu): void
    {
        $section = $menu->addChild('reports', [
            'label' => 'Reports',
            'extras' => [
                'icon' => 'report-analytics',
            ],
        ]);

        $section->addChild('daily_ledger', [
            'route' => '_daily_ledger',
            'label' => 'Daily Ledger',
            'extras' => [
                'icon' => 'report-money',
            ],
        ]);

        $section->addChild('monthly_sales', [
            'route' => '_monthly_sales',
            'label' => 'Monthly Sales Report',
            'extras' => [
                'icon' => 'calendar-stats',
            ],
        ]);

        $section->addChild('sales_analysis', [
            'route' => '_sales_analysis',
            'label' => 'Sales by Model',
            'extras' => [
                'icon' => 'chart-histogram',
            ],
        ]);

        $section->addChild('sales_by_client', [
            'route' => '_sales_by_client',
            'label' => 'Sales by Client',
            'extras' => [
                'icon' => 'users-group',
            ],
        ]);
    }
}
