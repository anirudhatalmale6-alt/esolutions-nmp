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

final class MarketplaceMenu
{
    public function __construct(
        private readonly MembershipManager $membership,
    ) {
    }

    // Premium section, just above "Online store" at the bottom of the sidebar
    // (priority 6 > store's 5, < system's 10). Any signed-in user sees it; the
    // gold crown marks it as a premium feature (styling in Layout/base.html.twig).
    #[MenuBuilder(name: 'sidebar', priority: 6, role: 'ROLE_USER')]
    public function sidebar(ItemInterface $menu): void
    {
        $extras = ['icon' => 'crown'];

        // Premium unlocks the Marketplace, and so does a grant the platform owner
        // has made for this business by name - so ask for Marketplace access, not
        // for Premium. Asking for Premium here left a business that had already
        // been given the Marketplace staring at an "Upgrade to Premium" badge on
        // a menu it could open perfectly well. Without access the item stays
        // visible but carries the badge; the pages themselves are blocked
        // server-side (MembershipGateListener).
        if (! $this->membership->currentHasMarketplaceAccess()) {
            $extras['plan_label'] = 'Premium';
        }

        // The word "Marketplace" IS the way in - clicking it opens the page
        // itself. It used to be a folder that had to be opened to reveal a
        // "Search" item pointing at the very same page, which put the thing
        // members come here for three clicks deep behind a menu they had to know
        // to open. The rest of the section (sharing stock, adverts) appears
        // underneath once you are inside it.
        $marketplace = $menu->addChild('marketplace', [
            'route' => '_marketplace',
            'label' => 'Marketplace',
            'attributes' => [
                'class' => 'premium-feature',
            ],
            'extras' => $extras,
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

        // A classified advert is not part of any plan - there are four places on
        // the Marketplace and the platform owner sells them one at a time. So the
        // item only appears for a business that has been sold one, rather than
        // appearing for everybody with an "upgrade" badge on it that upgrading
        // would not actually satisfy.
        if ($this->membership->currentHasClassifiedsAccess()) {
            $marketplace->addChild('marketplace.classifieds', [
                'route' => '_marketplace_classifieds',
                'label' => 'My Advert',
                'extras' => [
                    'icon' => 'photo',
                    'role' => 'ROLE_ADMIN',
                ],
            ]);
        }

        // The owner's side of the same thing: who is in which of the four places.
        $marketplace->addChild('marketplace.ads', [
            'route' => '_marketplace_ads',
            'label' => 'Adverts',
            'extras' => [
                'icon' => 'photo',
                'role' => 'ROLE_SUPER_ADMIN',
            ],
        ]);
    }
}
