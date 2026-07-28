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

namespace SolidInvoice\CoreBundle\Listener;

use SolidInvoice\CoreBundle\Membership\MembershipManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use function in_array;

/**
 * Server-side membership gate for the two Premium sales channels: sharing stock
 * onto the Marketplace, and the Online Store admin. Hiding/badging the menu is
 * not enough - a Basic member could still hit the URL directly, so we block the
 * actual routes here and bounce them to the upgrade page.
 *
 * Public shop-window pages (the marketplace search and the public storefront)
 * are deliberately NOT gated - they must stay reachable for guests/SEO.
 *
 * Runs on kernel.request at a priority below CompanyEventSubscriber (7) so the
 * current company is already selected by the time we check.
 */
final readonly class MembershipGateListener implements EventSubscriberInterface
{
    /**
     * The "use it" routes that require an active Premium membership.
     */
    private const GATED_ROUTES = [
        '_marketplace_settings',
        '_store_admin',
        '_store_import',
        '_store_image',
        '_store_delete',
    ];

    public function __construct(
        private MembershipManager $membership,
        private RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (! in_array($request->attributes->get('_route'), self::GATED_ROUTES, true)) {
            return;
        }

        $company = $this->membership->currentCompany();

        // No company context, or the company already has the sales channels:
        // nothing to block.
        if ($company === null || $this->membership->hasSalesChannels($company)) {
            return;
        }

        $session = $request->getSession();

        if ($session instanceof Session) {
            $session->getFlashBag()->add(
                'warning',
                'Marketplace and Online Store are Premium features. Upgrade to Premium to start using them.',
            );
        }

        $event->setResponse(new RedirectResponse($this->router->generate('_membership_upgrade')));
        $event->stopPropagation();
    }
}
