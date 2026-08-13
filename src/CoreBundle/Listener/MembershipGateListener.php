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
use function str_starts_with;

/**
 * The membership access funnel for the whole portal. In order, a signed-in user
 * working inside a company must clear:
 *
 *   1. A plan    - the company must be on an active Basic or Premium plan. A
 *                  business that joined through a sales rep's referral link is put
 *                  on Basic automatically, so it has access straight away. A
 *                  company with no active plan that has never been approved is held
 *                  on the "pending approval" page; if it was approved but its plan
 *                  lapsed, it is sent to the subscribe/upgrade page. Verification
 *                  ("trusted") is a separate manual badge - NOT required for Basic
 *                  access, only for a Premium upgrade.
 *   2. Premium   - the public sales channels (Online Store admin, Orders and
 *                  Unlock Codes) additionally require Premium. The Marketplace
 *                  is the exception: Premium unlocks it, but the platform owner
 *                  can also hand it to a business by name from the membership
 *                  console, without moving it onto Premium.
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
        '_stock_public_member',
        '_unlock_public',
    ];

    /**
     * The "use it" routes that require an active Premium membership specifically.
     * The Online Store, the Orders queue behind it, the Marketplace share and the
     * Unlock-Code tools are all Premium sales-channel features. (Every order route
     * is caught by the "_order" prefix check below, so new ones stay gated too.)
     * The public customer IMEI lookup (_unlock_public) stays free and open.
     */
    /**
     * The Marketplace, which Premium unlocks like the others - but which the
     * platform owner can also hand to a business by name from the membership
     * console, without moving it onto Premium. Kept apart from the list below
     * precisely so that grant opens the Marketplace and nothing else.
     */
    private const MARKETPLACE_ROUTES = [
        '_marketplace_settings',
    ];

    private const PREMIUM_ROUTES = [
        '_store_admin',
        '_store_import',
        '_store_image',
        '_store_delete',
        '_unlock_list',
        '_unlock_import',
        '_unlock_lookup',
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

        // 1 + 2. Plan / approval gate.
        //
        // A company on an active paid plan (Basic or Premium) has access, whether
        // or not it has been marked "verified". Verification is a separate trusted
        // badge the platform owner grants by hand - and the prerequisite for
        // Premium - but it is NOT required for Basic access. This lets a business
        // that joined through a sales rep's referral link (auto Basic for a year)
        // start working immediately, while the owner still reviews it and can mark
        // it trusted / upgrade it to Premium later.
        if (! $this->membership->isActive($company)) {
            // No active plan. If it has never been approved either, hold it on the
            // pending-approval page. Otherwise it is approved but its plan has
            // lapsed or is None, so send it to choose / renew a plan.
            if (! $this->membership->isVerified($company)) {
                $event->setResponse(new RedirectResponse($this->router->generate('_membership_pending')));
                $event->stopPropagation();

                return;
            }

            $this->flash($request, 'info', 'Choose a plan to start using your account.');
            $event->setResponse(new RedirectResponse($this->router->generate('_membership_upgrade')));
            $event->stopPropagation();

            return;
        }

        // 3a. Marketplace gate. Premium unlocks it, and so does a grant made by
        // the platform owner - checked first so a granted business is not sent to
        // the upgrade page for something it has already been given.
        if (in_array($route, self::MARKETPLACE_ROUTES, true)) {
            if (! $this->membership->hasMarketplaceAccess($company)) {
                $this->flash($request, 'warning', 'The Marketplace is a Premium feature. Upgrade to Premium, or ask us to enable it for your business.');
                $event->setResponse(new RedirectResponse($this->router->generate('_membership_upgrade')));
                $event->stopPropagation();
            }

            return;
        }

        // 3b. Premium sales-channel gate. Covers the explicit routes above plus
        // every Orders route (_order… prefix), which lives behind the store.
        if ((in_array($route, self::PREMIUM_ROUTES, true) || str_starts_with($route, '_order'))
            && ! $this->membership->hasSalesChannels($company)) {
            $this->flash($request, 'warning', 'The Online Store, Orders and Unlock Codes are Premium features. Upgrade to Premium to start using them.');
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
