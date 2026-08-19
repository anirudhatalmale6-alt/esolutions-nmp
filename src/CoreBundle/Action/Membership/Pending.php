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

namespace SolidInvoice\CoreBundle\Action\Membership;

use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Membership\MembershipManager;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Name\FullName;
use SolidInvoice\UserBundle\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function in_array;
use function mb_strlen;
use function preg_match;
use function strtoupper;
use function trim;

/**
 * "Your account is pending approval" holding page. A newly-registered business
 * lands here until the platform owner verifies (approves) it from the Memberships
 * console. Once approved, the user is free to move on to choosing a plan.
 *
 * It also collects what is missing. Some businesses reached the portal without
 * going through sign-up properly - the old "create a company" box asked for a
 * name and nothing else - and arrived here with no city, no country and no
 * contact number, which left the owner staring at a row he could not approve
 * because he had no idea who it was. Rather than leave those accounts stranded on
 * a page whose only button is "Log out", this page asks them for the missing
 * details itself.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class Pending extends AbstractController
{
    /**
     * Shown first in the country list - where this network's members actually
     * trade. Same order as the sign-up form.
     */
    private const PREFERRED_COUNTRIES = ['AE', 'SA', 'IN', 'HK', 'JP', 'CN', 'GB', 'US'];

    public function __construct(
        private readonly MembershipManager $membership,
        private readonly CompanyRepository $companyRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $company = $this->membership->currentCompany();

        // Already approved? No reason to sit on this page - move them along to
        // pick a plan (or straight to the dashboard if they already have one).
        if ($company instanceof Company && $this->membership->isVerified($company)) {
            return $this->redirectToRoute(
                $this->membership->isActive($company) ? '_dashboard' : '_membership_upgrade'
            );
        }

        $user = $this->getUser();
        $values = $this->currentValues($company, $user instanceof User ? $user : null);
        $errors = [];

        if ($request->isMethod('POST') && $company instanceof Company && $user instanceof User) {
            if (! $this->isCsrfTokenValid('membership_details', $request->request->getString('_token'))) {
                // A stale form after a long wait on this page, most likely.
                $this->addFlash('error', 'That form had expired - please try again.');

                return $this->redirectToRoute('_membership_pending');
            }

            $values = $this->submitted($request);
            $errors = $this->errorsIn($values);

            if ($errors === []) {
                $this->save($company, $user, $values);

                $this->addFlash('success', 'Thank you - we have your details. Your account is with us for approval.');

                return $this->redirectToRoute('_membership_pending');
            }
        }

        return $this->render('@SolidInvoiceCore/Membership/pending.html.twig', [
            'company' => $company,
            'values' => $values,
            'errors' => $errors,
            'needsDetails' => $this->needsDetails($company, $user instanceof User ? $user : null),
            'countries' => $this->countryChoices(),
        ]);
    }

    /**
     * Whether anything is actually missing. A business that gave everything at
     * sign-up is only waiting to be approved, and must not be asked to fill in a
     * form it has already filled in.
     */
    private function needsDetails(?Company $company, ?User $user): bool
    {
        if (! $company instanceof Company) {
            return false;
        }

        return trim((string) $company->getCity()) === ''
            || trim((string) $company->getCountry()) === ''
            || trim((string) $company->getContactNumber()) === ''
            || ($user instanceof User && FullName::of($user) === '');
    }

    /**
     * @return array{fullName: string, city: string, country: string, contactNumber: string}
     */
    private function currentValues(?Company $company, ?User $user): array
    {
        return [
            'fullName' => $user instanceof User ? FullName::of($user) : '',
            'city' => (string) $company?->getCity(),
            'country' => (string) $company?->getCountry(),
            'contactNumber' => (string) $company?->getContactNumber(),
        ];
    }

    /**
     * @return array{fullName: string, city: string, country: string, contactNumber: string}
     */
    private function submitted(Request $request): array
    {
        return [
            'fullName' => trim($request->request->getString('fullName')),
            'city' => trim($request->request->getString('city')),
            'country' => strtoupper(trim($request->request->getString('country'))),
            'contactNumber' => trim($request->request->getString('contactNumber')),
        ];
    }

    /**
     * The same rules sign-up applies, and for the same reason: a contact number
     * without its country code cannot be dialled from anywhere else, and this
     * network is not all in one country.
     *
     * @param array{fullName: string, city: string, country: string, contactNumber: string} $values
     * @return array<string, string>
     */
    private function errorsIn(array $values): array
    {
        $errors = [];

        if ($values['fullName'] === '') {
            $errors['fullName'] = 'Please enter your full name.';
        } elseif (mb_strlen($values['fullName']) > 90) {
            $errors['fullName'] = 'That name is too long.';
        }

        if ($values['city'] === '') {
            $errors['city'] = 'Please enter the city you trade from.';
        } elseif (mb_strlen($values['city']) > 100) {
            $errors['city'] = 'That city name is too long.';
        }

        if ($values['country'] === '' || ! Countries::exists($values['country'])) {
            $errors['country'] = 'Please choose your country.';
        }

        if ($values['contactNumber'] === '') {
            $errors['contactNumber'] = 'Please enter a contact number, including the country code.';
        } elseif (preg_match('/^\+[1-9]\d{0,3}[\s\-]?[\d\s\-]{5,17}$/', $values['contactNumber']) !== 1) {
            $errors['contactNumber'] = 'Please enter the number with its country code, like +971 50 123 4567.';
        }

        return $errors;
    }

    /**
     * @param array{fullName: string, city: string, country: string, contactNumber: string} $values
     */
    private function save(Company $company, User $user, array $values): void
    {
        $company
            ->setCity($values['city'])
            ->setCountry($values['country'])
            ->setContactNumber($values['contactNumber']);

        // The number is the member's own, so it goes on the account as well -
        // that is the field the support desk and the invitations read.
        $user->setMobile($values['contactNumber']);
        FullName::applyTo($user, $values['fullName']);

        // Held back so the two go in together: half-saved details are worse than
        // none, because the page would then stop asking for the rest.
        $this->companyRepository->save($company, false);
        $this->userRepository->save($user);
    }

    /**
     * Country names resolved in PHP - twig/intl-extra is not installed, so a
     * |country_name filter in the template would be a hard error.
     *
     * @return array<string, string>
     */
    private function countryChoices(): array
    {
        $names = Countries::getNames();
        $choices = [];

        foreach (self::PREFERRED_COUNTRIES as $code) {
            if (isset($names[$code])) {
                $choices[$code] = $names[$code];
            }
        }

        foreach ($names as $code => $name) {
            if (! in_array($code, self::PREFERRED_COUNTRIES, true)) {
                $choices[$code] = $name;
            }
        }

        return $choices;
    }
}
