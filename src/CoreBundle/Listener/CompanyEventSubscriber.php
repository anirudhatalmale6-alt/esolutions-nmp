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

use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Company\ResolvedHost;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Enum\UserSettingType;
use SolidInvoice\UserBundle\Repository\UserSettingRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Ulid;
use function assert;
use function count;
use function in_array;
use function is_string;

/**
 * @see \SolidInvoice\CoreBundle\Tests\Listener\CompanyEventSubscriberTest
 */
final readonly class CompanyEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RouterInterface $router,
        private CompanySelector $companySelector,
        private Security $security,
        private UserSettingRepositoryInterface $userSettingRepository,
        private ?string $installed = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 7],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (null === $this->installed || ! $event->isMainRequest() || $request->attributes->get('_stateless')) {
            return;
        }

        if ($this->companySelector->getCompany() instanceof Ulid) {
            return;
        }

        $session = $request->getSession();
        assert($session instanceof SessionInterface);

        $resolved = $request->attributes->get(HostRoutingListener::REQUEST_ATTR);

        if ($resolved instanceof ResolvedHost && $resolved->isCustomDomain() && $resolved->company instanceof Company) {
            $companyId = $resolved->company->getId();
            $this->companySelector->switchCompany($companyId);
            $session->set('company', $companyId);
            return;
        }

        if ($session->has('company')) {
            $sessionCompany = $session->get('company');
            $user = $this->security->getUser();

            // Only honour the company stored in the session if the signed-in user
            // is actually a member of it. Otherwise a previous user's company can
            // bleed through on a shared browser (they log out, the next user logs
            // in on the same session and would inherit the old company's name,
            // logo and data). If it doesn't belong to them, drop it and fall
            // through to pick one of THEIR own companies below.
            if (! $user instanceof User || $this->userInCompany($user, $sessionCompany)) {
                $this->companySelector->switchCompany($sessionCompany);

                return;
            }

            $session->remove('company');
            $this->companySelector->reset();
        }

        if (! $this->isOnCompanySelectionRoute($request) && ($user = $this->security->getUser()) instanceof UserInterface) {
            assert($user instanceof User);

            if (count($user->getCompanies()) === 1) {
                $this->companySelector->switchCompany($user->getCompanies()->first()->getId());
                $session->set('company', $user->getCompanies()->first()->getId());
                return;
            }

            // Nobody with an account but no business yet gets sent anywhere except
            // sign-up. The old route out of here was "select a company" -> "create
            // a company", a bare box asking only for a name, which left the
            // business with no city, no country, no contact number, no sales rep
            // against it and no plan - so it sat on the pending-approval page and
            // the owner had no idea who had just joined.
            if (count($user->getCompanies()) === 0) {
                $event->setResponse(new RedirectResponse($this->router->generate('_onboarding')));
                $event->stopPropagation();

                return;
            }

            // More than one business, and a fresh session: take them back to the
            // one they were last working in instead of making them pick from a
            // list. The session cannot do this - it dies with the browser, so
            // the list came back at every single login. The choice is kept
            // against the account (UserSettingType::LastCompany) and re-checked
            // against their OWN businesses here, so unlike the session value it
            // can never carry somebody into a business they are not a member of.
            $remembered = $this->rememberedCompany($user);

            if ($remembered instanceof Ulid) {
                $this->companySelector->switchCompany($remembered);
                $session->set('company', $remembered);

                return;
            }

            $event->setResponse(new RedirectResponse($this->router->generate('_select_company')));
            $event->stopPropagation();
        }
    }

    /**
     * The business this user was last working in, or null if there isn't one,
     * it is unreadable, or they are no longer a member of it.
     */
    private function rememberedCompany(User $user): ?Ulid
    {
        $value = $this->userSettingRepository->getSetting($user, UserSettingType::LastCompany)?->getValue();

        if (! is_string($value) || ! Ulid::isValid($value) || ! $this->userInCompany($user, $value)) {
            return null;
        }

        return Ulid::fromString($value);
    }

    /**
     * Whether the user is a member of the given company (id may be a Ulid or its
     * string form as pulled from the session).
     */
    private function userInCompany(User $user, mixed $companyId): bool
    {
        $companyId = (string) $companyId;

        foreach ($user->getCompanies() as $company) {
            if ((string) $company->getId() === $companyId) {
                return true;
            }
        }

        return false;
    }

    private function isOnCompanySelectionRoute(Request $request): bool
    {
        $routeName = $request->attributes->get('_route');

        return in_array(
            $routeName,
            [
                '2fa_login',
                '_select_company',
                '_switch_company',
                '_create_company',
                '_onboarding',
            ],
            true
        );
    }
}
