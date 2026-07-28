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
use SolidInvoice\CoreBundle\Membership\MembershipManager;
use SolidWorx\Platform\PlatformBundle\Attributes\Menu\MenuBuilder;

final class StoreMenu
{
    public function __construct(
        private readonly MembershipManager $membership,
    ) {
    }

    // Priority below PRIORITY_SYSTEM (10) so the store sits on its own, at the
    // very bottom of the sidebar - visually separated from the invoicing tools.
    #[MenuBuilder(name: 'sidebar', priority: 5, role: 'ROLE_MANAGER')]
    public function sidebar(ItemInterface $menu): void
    {
        $extras = [
            // Gold crown = the premium marker (styled in Layout/base.html.twig).
            'icon' => 'crown',
        ];

        // Online Store is a Premium sales channel. Non-Premium companies see it
        // with an "Upgrade to Premium" badge; the admin pages are blocked
        // server-side (MembershipGateListener).
        if (! $this->membership->currentHasSalesChannels()) {
            $extras['plan_label'] = 'Premium';
        }

        $menu->addChild('store', [
            'route' => '_store_admin',
            'label' => 'Online store',
            'attributes' => [
                // Hook for the premium gold styling (see Layout/base.html.twig).
                'class' => 'premium-feature',
            ],
            'extras' => $extras,
        ]);
    }
}
