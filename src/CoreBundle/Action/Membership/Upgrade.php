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
use SolidInvoice\CoreBundle\Membership\MembershipPlan;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The "Upgrade to Premium" landing page. A Basic member is bounced here when
 * they try to open a Premium sales channel (Marketplace share / Online Store).
 * It presents the two tiers and their prices. The actual Stripe checkout button
 * is wired on top of this page in the next milestone.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class Upgrade extends AbstractController
{
    // Annual prices in AED. Kept here as named constants for now; these move to
    // editable system settings when the Stripe checkout is wired up.
    private const BASIC_PRICE_AED = 499;

    private const PREMIUM_PRICE_AED = 999;

    public function __construct(
        private readonly MembershipManager $membership,
    ) {
    }

    public function __invoke(): Response
    {
        $company = $this->membership->currentCompany();

        return $this->render('@SolidInvoiceCore/Membership/upgrade.html.twig', [
            'currentPlan' => $company !== null ? $this->membership->effectivePlan($company) : MembershipPlan::None,
            'verified' => $company !== null && $company->isVerified(),
            'basicPrice' => self::BASIC_PRICE_AED,
            'premiumPrice' => self::PREMIUM_PRICE_AED,
        ]);
    }
}
