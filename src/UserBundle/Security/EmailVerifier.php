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

namespace SolidInvoice\UserBundle\Security;

use DateTimeImmutable;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * @see \SolidInvoice\UserBundle\Tests\Security\EmailVerifierTest
 */
final readonly class EmailVerifier
{
    /**
     * The marker put in the WhatsApp copy of the link. Short and opaque on
     * purpose - it appears in a message a customer reads.
     */
    public const string VIA_WHATSAPP = 'w';

    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MailerInterface $mailer,
        private UserRepository $userRepository
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function sendEmailConfirmation(string $verifyEmailRouteName, User $user, TemplatedEmail $email): void
    {
        // Signed here rather than taken from the caller. The two channels used to
        // share one signature; they must not now, because the emailed link is the
        // one that means "this address works" and handing it the WhatsApp copy
        // would put a tick against an inbox nobody has opened.
        $signatureComponents = $this->signature($verifyEmailRouteName, $user);

        $context = $email->getContext();
        $context['signedUrl'] = $signatureComponents->getSignedUrl();
        $context['expiresAtMessageKey'] = $signatureComponents->getExpirationMessageKey();
        $context['expiresAtMessageData'] = $signatureComponents->getExpirationMessageData();

        $email->context($context);

        $this->mailer->send($email);
    }

    /**
     * The confirmation link on its own, for a channel that is not email.
     *
     * Verification also goes out over WhatsApp now, and that must not depend on
     * the mail send working - which is the entire reason it exists. So the
     * caller signs here first and sends that over WhatsApp, before the mail is
     * attempted at all.
     *
     * $via is carried INSIDE the signature, so the link that arrives on WhatsApp
     * is not the same string as the one that arrives by email and opening it
     * says which of the two the person actually has. It cannot be tampered with
     * on the way: the signature is computed over the whole URL, so an emailed
     * link with `via` bolted on by hand simply fails to validate.
     */
    public function signature(string $verifyEmailRouteName, User $user, string $via = ''): VerifyEmailSignatureComponents
    {
        $parameters = ['id' => $user->getId()?->toBase58()];

        if ($via !== '') {
            $parameters['via'] = $via;
        }

        return $this->verifyEmailHelper->generateSignature(
            $verifyEmailRouteName,
            $user->getId()->toString(),
            $user->getEmail(),
            $parameters
        );
    }

    public function handleEmailConfirmation(Request $request, User $user): void
    {
        $this->verifyEmailHelper->validateEmailConfirmationFromRequest($request, $user->getId()->toString(), $user->getEmail());

        // The account is activated whichever link was opened - that has always
        // been true and nothing here changes who can log in. What is new is that
        // the channel is written down as well, so the owner can see what has
        // actually been proved about this person rather than only that they
        // clicked something.
        $now = new DateTimeImmutable();

        if (self::VIA_WHATSAPP === $request->query->get('via')) {
            // Left alone if already set, so the record keeps the date it was
            // first confirmed rather than the last time the link was opened.
            if (! $user->isMobileVerified()) {
                $user->setMobileVerifiedAt($now);
            }
        } elseif (! $user->isEmailVerified()) {
            $user->setEmailVerifiedAt($now);
        }

        $user->setVerified(true);

        $this->userRepository->save($user);
    }
}
