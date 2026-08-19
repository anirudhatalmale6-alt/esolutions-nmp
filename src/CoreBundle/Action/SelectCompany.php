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

namespace SolidInvoice\CoreBundle\Action;

use Doctrine\Common\Collections\Collection;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Enum\UserSettingType;
use SolidInvoice\UserBundle\Repository\UserSettingRepositoryInterface;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Ulid;

final readonly class SelectCompany
{
    public function __construct(
        private Security $security,
        private RouterInterface $router,
        private UserSettingRepositoryInterface $userSettingRepository,
    ) {
    }

    /**
     * @return array{companies: Collection<int, Company>}|Response
     */
    #[Template('@SolidInvoiceCore/Company/select.html.twig')]
    public function __invoke(Request $request): array|Response
    {
        $user = $this->security->getUser();

        assert($user instanceof User);

        $companies = $user->getCompanies();

        // An account with no business goes to sign-up, not to the bare create-a-
        // company box. That box asks for a name and a currency and nothing else,
        // so a business that came in through it had no city, no country, no
        // contact number, no rep and no plan - see CompanyEventSubscriber.
        if ($companies->count() === 0) {
            return new RedirectResponse($this->router->generate('_onboarding'));
        }

        if ($companies->count() === 1) {
            $request->getSession()->set('company', $companies->first()->getId());
            $this->remember($user, $companies->first()->getId());

            return new RedirectResponse($this->resolvePostLoginTarget($request));
        }

        return ['companies' => $companies];
    }

    public function switchCompany(Request $request, string $id): RedirectResponse
    {
        $uuid = Ulid::fromString($id);

        $user = $this->security->getUser();

        assert($user instanceof User);

        $companies = $user->getCompanies();

        if ($companies->exists(static fn (int $key, Company $company) => $company->getId()->equals($uuid))) {
            $request->getSession()->set('company', $uuid);
            $this->remember($user, $uuid);

            return new RedirectResponse($this->resolvePostLoginTarget($request));
        }

        throw new BadRequestHttpException('Invalid company');
    }

    /**
     * Keep this choice on the account so the next login lands straight back in
     * it. The session alone cannot: it dies with the browser, which is why
     * somebody with two businesses was shown this list every single time they
     * signed in. CompanyEventSubscriber re-checks the stored id against the
     * user's own businesses before acting on it.
     */
    private function remember(User $user, Ulid $companyId): void
    {
        if ($this->userSettingRepository->getSetting($user, UserSettingType::LastCompany)?->getValue() === (string) $companyId) {
            return;
        }

        $this->userSettingRepository->saveSetting($user, UserSettingType::LastCompany, (string) $companyId);
    }

    /**
     * Honour the target URL Symfony Security saved when the user was bounced
     * to the login page (e.g. deep-link to /oauth/authorize). Fall back to the
     * dashboard if nothing was captured.
     */
    private function resolvePostLoginTarget(Request $request): string
    {
        $session = $request->getSession();
        $target = $session->get('_security.main.target_path');

        if (\is_string($target) && $target !== '') {
            $session->remove('_security.main.target_path');

            return $target;
        }

        return $this->router->generate('_dashboard');
    }
}
