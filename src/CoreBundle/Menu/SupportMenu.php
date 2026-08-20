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
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Repository\SupportTicketRepository;
use SolidWorx\Platform\PlatformBundle\Attributes\Menu\MenuBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Help & Support in the sidebar, for everybody, with the number of replies
 * waiting on it.
 *
 * A support desk that has to be gone looking for is a support desk nobody uses,
 * which is the state this was in before: the only way to report a broken page
 * was to already have the owner's phone number.
 */
final class SupportMenu
{
    public function __construct(
        private readonly SupportTicketRepository $tickets,
        private readonly CompanySelector $companySelector,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    // Priority 9 puts it under the premium block (4-6) and above System (10) -
    // near the bottom, where people look for help, but never in the way.
    #[MenuBuilder(name: 'sidebar', priority: 9, role: 'ROLE_USER')]
    public function sidebar(ItemInterface $menu): void
    {
        $extras = ['icon' => 'message-circle'];
        $unread = $this->unreadForCurrentCompany();

        if ($unread > 0) {
            $extras['badge'] = (string) $unread;
        }

        $menu->addChild('support', [
            'route' => '_support',
            'label' => 'Help & Support',
            'extras' => $extras,
        ]);
    }

    // The other side of the same desk. Super-admin only, and it carries the
    // count of what is waiting so the owner does not have to open it to find out.
    #[MenuBuilder(name: 'sidebar', priority: 10, role: 'ROLE_SUPER_ADMIN')]
    public function desk(ItemInterface $menu): void
    {
        $extras = ['icon' => 'messages'];
        $waiting = $this->countAwaiting();

        if ($waiting > 0) {
            $extras['badge'] = (string) $waiting;
        }

        $desk = $menu->addChild('support.desk', [
            'route' => '_support_desk',
            'label' => 'Support Desk',
            'extras' => $extras,
        ]);

        // Under the desk rather than in Settings: "why did that email not
        // arrive" is a support question, and it is asked at the moment somebody
        // says they never heard back.
        $desk->addChild('support.email_check', [
            'route' => '_email_check',
            'label' => 'Email Check',
            'extras' => [
                'icon' => 'mail-cog',
                'role' => 'ROLE_SUPER_ADMIN',
            ],
        ]);

        // Next to it, for the same reason: the other channel fails silently too,
        // and falling back to email is exactly what hides it.
        $desk->addChild('support.whatsapp_check', [
            'route' => '_whatsapp_check',
            'label' => 'WhatsApp Check',
            'extras' => [
                'icon' => 'brand-whatsapp',
                'role' => 'ROLE_SUPER_ADMIN',
            ],
        ]);
    }

    /**
     * A count for a menu badge is never worth taking a page down for. If the
     * table is not there yet (the migration has not run) or the query fails for
     * any reason, the menu still renders - just without a number on it.
     */
    private function unreadForCurrentCompany(): int
    {
        try {
            $companyId = $this->companySelector->getCompany();

            if ($companyId === null) {
                return 0;
            }

            $company = $this->entityManager->find(Company::class, $companyId);

            return $company instanceof Company ? $this->tickets->countUnreadForCompany($company) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function countAwaiting(): int
    {
        try {
            return $this->tickets->countAwaitingOwner();
        } catch (Throwable) {
            return 0;
        }
    }
}
