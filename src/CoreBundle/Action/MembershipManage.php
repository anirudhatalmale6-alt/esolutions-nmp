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

use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Membership\MembershipManager;
use SolidInvoice\CoreBundle\Membership\MembershipPlan;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/**
 * Super-user membership console. The platform owner sees every vendor company
 * with its plan, expiry, verified badge and comp status, and can:
 *   - verify / un-verify a company
 *   - set its plan (None / Basic / Premium) for an annual term or lifetime
 *   - grant it complimentary (free) - no Stripe charge
 *
 * Premium cannot be granted to an unverified company (matches the rule that
 * verification is required for Premium). Only the Super User (platform owner)
 * may open this page.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class MembershipManage extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly MembershipManager $membership,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleSave($request);
        }

        $companies = $this->companyRepository->findBy([], ['name' => 'ASC']);

        $rows = [];
        foreach ($companies as $company) {
            $rows[] = [
                'company' => $company,
                'plan' => $this->membership->planFor($company),
                'active' => $this->membership->isActive($company),
                'expiresAt' => $company->getMembershipExpiresAt(),
                'complimentary' => $company->isMembershipComplimentary(),
                'verified' => $company->isVerified(),
            ];
        }

        return $this->render('@SolidInvoiceCore/Membership/manage.html.twig', [
            'rows' => $rows,
            'plans' => MembershipPlan::cases(),
        ]);
    }

    private function handleSave(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('membership.manage', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_membership_manage');
        }

        $companyId = (string) $request->request->get('company');
        $company = Ulid::isValid($companyId) ? $this->companyRepository->find(Ulid::fromString($companyId)) : null;

        if (! $company instanceof Company) {
            $this->addFlash('error', 'That company could not be found.');

            return $this->redirectToRoute('_membership_manage');
        }

        $plan = MembershipPlan::fromValue((string) $request->request->get('plan'));
        $verified = $request->request->getBoolean('verified');
        $complimentary = $request->request->getBoolean('complimentary');
        $term = (string) $request->request->get('term', 'annual');

        // Verification is a prerequisite for Premium.
        if ($plan === MembershipPlan::Premium && ! $verified) {
            $this->addFlash('error', sprintf('%s must be verified before it can be put on Premium. Tick "Verified" and save again.', $company->getName()));

            return $this->redirectToRoute('_membership_manage');
        }

        // Persist verification first so it is stored even for a None/Basic plan.
        $this->membership->setVerified($company, $verified);

        // Work out the expiry: no plan or a "lifetime" term means no expiry;
        // otherwise one year from today.
        $expiresAt = null;
        if ($plan->isPaid() && $term === 'annual') {
            $expiresAt = new \DateTimeImmutable('+1 year');
        }

        $this->membership->grant($company, $plan, $expiresAt, $plan->isPaid() && $complimentary);

        $this->addFlash('success', sprintf('%s updated: %s%s.', $company->getName(), $plan->label(), $complimentary && $plan->isPaid() ? ' (complimentary)' : ''));

        return $this->redirectToRoute('_membership_manage');
    }
}
