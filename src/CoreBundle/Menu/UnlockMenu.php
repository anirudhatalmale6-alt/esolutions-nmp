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

final class UnlockMenu
{
    public function __construct(
        private readonly MembershipManager $membership,
    ) {
    }

    // Priority 4 keeps it in the bottom premium group, alongside the Online store
    // (5) and Marketplace (6) - the three Premium sales-channel tools together.
    #[MenuBuilder(name: 'sidebar', priority: 4, role: 'ROLE_MANAGER')]
    public function sidebar(ItemInterface $menu): void
    {
        $extras = [
            // Gold crown = the premium marker (styled in Layout/base.html.twig).
            'icon' => 'crown',
        ];

        // Unlock Codes is a Premium tool. Non-Premium companies see it with an
        // "Upgrade to Premium" badge; the admin pages are blocked server-side
        // (MembershipGateListener). The public customer IMEI lookup stays free.
        if (! $this->membership->currentHasSalesChannels()) {
            $extras['plan_label'] = 'Premium';
        }

        $menu->addChild('unlock', [
            'route' => '_unlock_list',
            'label' => 'Unlock codes',
            'attributes' => [
                'class' => 'premium-feature',
            ],
            'extras' => $extras,
        ]);
    }
}
