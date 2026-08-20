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
    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MailerInterface $mailer,
        private UserRepository $userRepository
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function sendEmailConfirmation(string $verifyEmailRouteName, User $user, TemplatedEmail $email, ?VerifyEmailSignatureComponents $signature = null): void
    {
        $signatureComponents = $signature ?? $this->signature($verifyEmailRouteName, $user);

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
     * caller signs once, sends it over WhatsApp, and hands the SAME signature to
     * sendEmailConfirmation() rather than having a second one generated behind
     * its back: one registration, one signature, one link in both messages.
     */
    public function signature(string $verifyEmailRouteName, User $user): VerifyEmailSignatureComponents
    {
        return $this->verifyEmailHelper->generateSignature(
            $verifyEmailRouteName,
            $user->getId()->toString(),
            $user->getEmail(),
            ['id' => $user->getId()?->toBase58()]
        );
    }

    public function handleEmailConfirmation(Request $request, User $user): void
    {
        $this->verifyEmailHelper->validateEmailConfirmationFromRequest($request, $user->getId()->toString(), $user->getEmail());

        $user->setVerified(true);

        $this->userRepository->save($user);
    }
}
