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

namespace SolidInvoice\UserBundle\Enum;

enum UserSettingType: string
{
    case Timezone = 'timezone';
    case Location = 'location';
    case OnboardComplete = 'onboard_complete';
    case OnboardingStep = 'onboarding_step';
    case OnboardingSkipped = 'onboarding_skipped';
    case OnboardingStartedAt = 'onboarding_started_at';
    case OnboardingCompletedAt = 'onboarding_completed_at';
    case OnboardingChecklistDismissed = 'onboarding_checklist_dismissed';
    case OnboardingEmailSequenceLastStep = 'onboarding_email_sequence_last_step';

    // Which sales rep's link brought this account in. Captured at registration
    // and kept on the account itself, not only in the session: somebody who
    // signs up on their phone, closes the browser and finishes the next morning
    // used to arrive as an unreferred stranger with no plan.
    case ReferralCode = 'referral_code';

    case ReferralName = 'referral_name';
}
