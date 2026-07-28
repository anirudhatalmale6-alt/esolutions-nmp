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

use SolidInvoice\CoreBundle\Membership\MembershipManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Your account is pending approval" holding page. A newly-registered business
 * lands here until the platform owner verifies (approves) it from the Memberships
 * console. Once approved, the user is free to move on to choosing a plan.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class Pending extends AbstractController
{
    public function __construct(
        private readonly MembershipManager $membership,
    ) {
    }

    public function __invoke(): Response
    {
        $company = $this->membership->currentCompany();

        // Already approved? No reason to sit on this page - move them along to
        // pick a plan (or straight to the dashboard if they already have one).
        if ($company !== null && $this->membership->isVerified($company)) {
            return $this->redirectToRoute(
                $this->membership->isActive($company) ? '_dashboard' : '_membership_upgrade'
            );
        }

        return $this->render('@SolidInvoiceCore/Membership/pending.html.twig', [
            'company' => $company,
        ]);
    }
}
