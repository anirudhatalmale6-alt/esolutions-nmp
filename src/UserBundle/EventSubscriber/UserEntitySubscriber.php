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

namespace SolidInvoice\UserBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use SolidInvoice\CoreBundle\WhatsApp\VerificationNotifier;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Security\EmailVerifier;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * @see \SolidInvoice\UserBundle\Tests\EventSubscriber\UserEntitySubscriberTest
 */
#[AsEntityListener(event: Events::postPersist, entity: User::class)]
final readonly class UserEntitySubscriber
{
    public function __construct(
        private EmailVerifier $emailVerifier,
        private LoggerInterface $logger,
        private VerificationNotifier $whatsapp,
    ) {
    }

    public function postPersist(User $user): void
    {
        if ($user->isVerified()) {
            return;
        }

        // Signed once, here, and used by both channels. WhatsApp goes first so
        // it does not depend on the mail send working - that is the whole reason
        // it is here - and the same signature is handed to the email below, so
        // both messages carry one identical link.
        $signature = null;

        try {
            $signature = $this->emailVerifier->signature('_verify_email', $user);

            $this->whatsapp->sendVerification($user->getMobile(), $signature->getSignedUrl());
        } catch (Throwable $e) {
            // sendVerification() swallows its own failures, so reaching here
            // means signing the URL itself broke. Still not worth losing the
            // registration over - the account exists and can be verified by
            // hand from the Users page.
            $this->logger->error('Failed to send WhatsApp confirmation', [
                'exception' => $e,
            ]);
        }

        try {
            $this->emailVerifier->sendEmailConfirmation(
                '_verify_email',
                $user,
                new TemplatedEmail()
                    ->to($user->getEmail())
                    ->subject('Please Confirm your Email')
                    ->htmlTemplate('@SolidInvoiceUser/Email/confirm_email.html.twig'),
                $signature,
            );
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Failed to send email confirmation', [
                'exception' => $e,
            ]);
        }
    }
}
