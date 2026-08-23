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

        // WhatsApp goes first so it does not depend on the mail send working -
        // that is the whole reason it is here.
        //
        // Each channel gets its own signature. They used to share one, which
        // meant the two messages carried a single identical link and opening it
        // proved only that the person had one of the two - the owner could not
        // see whether the number answered or the address did. The WhatsApp copy
        // is now marked as such inside its signature, so it says which.
        try {
            $whatsappUrl = $this->emailVerifier
                ->signature('_verify_email', $user, EmailVerifier::VIA_WHATSAPP)
                ->getSignedUrl();

            $this->whatsapp->sendVerification($user->getMobile(), $whatsappUrl);
        } catch (Throwable $e) {
            // sendVerification() swallows its own failures, so reaching here
            // means signing the URL itself broke. Still not worth losing the
            // registration over - the account exists, the email below still
            // carries a link, and the Users page shows it as unconfirmed until
            // one of them is opened.
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
            );
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Failed to send email confirmation', [
                'exception' => $e,
            ]);
        }
    }
}
