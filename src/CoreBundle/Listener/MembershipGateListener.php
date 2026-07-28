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
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use function in_array;

/**
 * The membership access funnel for the whole portal. In order, a signed-in user
 * working inside a company must clear:
 *
 *   1. Approval  - the company must be verified (approved) by the platform owner.
 *                  Until then the user is held on the "pending approval" page.
 *                  This is what keeps the portal to real wholesalers/distributors.
 *   2. A plan    - the company must be on an active Basic or Premium plan. With
 *                  no plan the user can't use anything and is sent to the
 *                  subscribe/upgrade page.
 *   3. Premium   - the two public sales channels (Marketplace share + Online
 *                  Store admin) additionally require Premium.
 *
 * The platform owner (ROLE_SUPER_ADMIN) is never gated. Public shop-window pages,
 * login/registration, onboarding, company switching, profile and the membership
 * pages themselves are always reachable so a gated user is never trapped.
 *
 * Runs on kernel.request at priority 0 - after the firewall (8) and the company
 * selection (7), so both the user and the current company are known.
 */
final readonly class MembershipGateListener implements EventSubscriberInterface
{
    /**
     * Routes reachable regardless of approval/plan, so a gated user can still log
     * out, switch/create a company, finish onboarding, browse the public pages,
     * see why they're blocked and (once available) pay.
     */
    private const ALWAYS_ALLOWED = [
        // session / auth
        '_logout',
        '2fa_login',
        // company context
        '_select_company',
        '_switch_company',
        '_create_company',
        '_delete_company',
        // onboarding
        '_onboarding',
        '_dashboard_onboarding_dismiss',
        // membership funnel pages
        '_membership_pending',
        '_membership_upgrade',
        '_membership_manage',
        // profile
        '_profile',
        '_edit_profile',
        '_profile_notifications',
        // public shop-window pages (viewable while gated)
        '_home',
        '_marketplace',
        '_store_front',
        '_stock_public',
        '_unlock_public',
        '_unlock_lookup',
    ];

    /**
     * The "use it" routes that require an active Premium membership specifically.
     */
    private const PREMIUM_ROUTES = [
        '_marketplace_settings',
        '_store_admin',
        '_store_import',
        '_store_image',
        '_store_delete',
    ];

    public function __construct(
        private MembershipManager $membership,
        private RouterInterface $router,
        private Security $security,
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

        if ($request->attributes->get('_stateless')) {
            return;
        }

        $route = $request->attributes->get('_route');

        if ($route === null || in_array($route, self::ALWAYS_ALLOWED, true)) {
            return;
        }

        // Guests (login/register/public pages) are not gated here.
        if ($this->security->getUser() === null) {
            return;
        }

        // The platform owner always has full access - never lock the owner out.
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return;
        }

        $company = $this->membership->currentCompany();

        // No company selected yet: CompanyEventSubscriber handles that redirect.
        if ($company === null) {
            return;
        }

        // 1. Approval gate.
        if (! $this->membership->isVerified($company)) {
            $event->setResponse(new RedirectResponse($this->router->generate('_membership_pending')));
            $event->stopPropagation();

            return;
        }

        // 2. Plan gate - no active plan means no access to the portal at all.
        if (! $this->membership->isActive($company)) {
            $this->flash($request, 'info', 'Choose a plan to start using your account.');
            $event->setResponse(new RedirectResponse($this->router->generate('_membership_upgrade')));
            $event->stopPropagation();

            return;
        }

        // 3. Premium sales-channel gate.
        if (in_array($route, self::PREMIUM_ROUTES, true) && ! $this->membership->hasSalesChannels($company)) {
            $this->flash($request, 'warning', 'Marketplace and Online Store are Premium features. Upgrade to Premium to start using them.');
            $event->setResponse(new RedirectResponse($this->router->generate('_membership_upgrade')));
            $event->stopPropagation();
        }
    }

    private function flash(\Symfony\Component\HttpFoundation\Request $request, string $type, string $message): void
    {
        $session = $request->getSession();

        if ($session instanceof Session) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
