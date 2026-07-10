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

namespace SolidInvoice\UserBundle\Action;

use SolidInvoice\UserBundle\Entity\UserInvitation as UserInvitationEntity;
use SolidInvoice\UserBundle\Repository\UserInvitationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Shows a shareable accept-invitation link for a pending invitation, so an
 * admin can send it directly (e.g. WhatsApp) without relying on email delivery.
 */
final class ShowInviteLink extends AbstractController
{
    public function __construct(
        private readonly UserInvitationRepository $invitationRepository,
        private readonly RouterInterface $router,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        if (! Ulid::isValid($id)) {
            throw new NotFoundHttpException('Invitation is not valid');
        }

        $invitation = $this->invitationRepository->find(Ulid::fromString($id));

        if (! $invitation instanceof UserInvitationEntity) {
            throw new NotFoundHttpException('Invitation is not valid');
        }

        $link = $this->router->generate(
            '_user_accept_invite',
            ['id' => (string) $invitation->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return $this->render('@SolidInvoiceUser/Users/invite_link.html.twig', [
            'invitation' => $invitation,
            'link' => $link,
            'emailSent' => $request->query->getBoolean('sent'),
        ]);
    }
}
