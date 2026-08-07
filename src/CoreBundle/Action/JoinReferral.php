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

use SolidInvoice\CoreBundle\Entity\ReferralLink;
use SolidInvoice\CoreBundle\Repository\ReferralLinkRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The friendly public entry point a sales rep shares, e.g.
 * https://b2bnetwork.ae/join/RASHID. It validates the rep's code and forwards to
 * the registration form with the referral attached (?ref=CODE). An unknown or
 * disabled code falls through to the registration page, which shows a friendly
 * "invitation only - contact the B2B Network Team" message (never a bare 404).
 */
final class JoinReferral extends AbstractController
{
    public function __construct(
        private readonly ReferralLinkRepository $referralRepository,
    ) {
    }

    public function __invoke(string $code): RedirectResponse
    {
        $link = $this->referralRepository->findActiveByCode($code);

        if (! $link instanceof ReferralLink) {
            // Unknown or paused link - send them to the registration page without a
            // referral, where they get the friendly "invitation only" message asking
            // them to contact the B2B Network Team, instead of a bare 404.
            return $this->redirectToRoute('_register');
        }

        return $this->redirectToRoute('_register', ['ref' => $link->getCode()]);
    }
}
