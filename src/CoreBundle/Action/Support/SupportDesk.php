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

namespace SolidInvoice\CoreBundle\Action\Support;

use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Entity\SupportMessage;
use SolidInvoice\CoreBundle\Entity\SupportTicket;
use SolidInvoice\CoreBundle\Enum\SupportTicketStatus;
use SolidInvoice\CoreBundle\Repository\SupportTicketRepository;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function mb_substr;
use function trim;

/**
 * The platform owner's side of the support desk: every ticket from every
 * business, oldest unanswered first.
 *
 * Reads run with the company filter switched off. Tickets belong to the member's
 * company, and the filter scopes everything to the company the owner is
 * currently inside - which would show them their own tickets and nobody else's,
 * i.e. an empty support desk with a queue behind it. Same reason the membership
 * console does it.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class SupportDesk extends AbstractController
{
    private const int BODY_MAX = 5000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SupportTicketRepository $tickets,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handlePost($request);
        }

        $tickets = $this->tickets->findAllForOwnerUnscoped();

        return $this->render('@SolidInvoiceCore/Support/desk.html.twig', [
            'tickets' => $tickets,
            'statuses' => SupportTicketStatus::cases(),
            'bodyMax' => self::BODY_MAX,
        ]);
    }

    private function handlePost(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('support.desk', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_support_desk');
        }

        $ticket = $this->withoutCompanyFilter(
            fn (): ?SupportTicket => $this->entityManager->find(SupportTicket::class, (string) $request->request->get('ticket'))
        );

        if (! $ticket instanceof SupportTicket) {
            $this->addFlash('error', 'That ticket could not be found.');

            return $this->redirectToRoute('_support_desk');
        }

        $body = trim((string) $request->request->get('body', ''));
        $status = $request->request->has('status')
            ? SupportTicketStatus::fromValue((string) $request->request->get('status'))
            : null;

        if ($body !== '') {
            $message = new SupportMessage();
            $message
                ->setBody(mb_substr($body, 0, self::BODY_MAX))
                ->setFromOwner(true);

            $user = $this->getUser();

            if ($user instanceof User) {
                $message->setAuthor($user);
            }

            // addMessage() reopens a closed ticket when the owner writes on it, so
            // the status posted alongside is applied afterwards - otherwise
            // "reply and close" would reopen what it just closed.
            $ticket->addMessage($message);
        }

        if ($status !== null) {
            $ticket->setStatus($status);
        }

        if ($body === '' && $status === null) {
            $this->addFlash('error', 'Nothing to do - write a reply or change the status.');

            return $this->redirectToRoute('_support_desk');
        }

        $this->entityManager->flush();

        $this->addFlash('success', $body === '' ? 'Status updated.' : 'Reply sent.');

        return $this->redirectToRoute('_support_desk');
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withoutCompanyFilter(callable $callback): mixed
    {
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('company');

        if ($wasEnabled) {
            $filters->disable('company');
        }

        try {
            return $callback();
        } finally {
            if ($wasEnabled) {
                $filters->enable('company');
            }
        }
    }
}
