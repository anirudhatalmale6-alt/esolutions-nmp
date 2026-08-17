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
use SolidInvoice\CoreBundle\Repository\Traits\WithoutCompanyFilter;
use SolidInvoice\CoreBundle\Support\SupportNotifier;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function mb_substr;
use function sprintf;
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
    use WithoutCompanyFilter;

    private const int BODY_MAX = 5000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SupportTicketRepository $tickets,
        private readonly SupportNotifier $notifier,
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
            'whatsapp' => $this->whatsappLinks($tickets),
        ]);
    }

    /**
     * A WhatsApp link for every reply already written, keyed by the message it
     * carries. Built here rather than in the template so a business with no
     * number on file simply has no button, instead of a link that opens a
     * contact picker.
     *
     * @param list<SupportTicket> $tickets
     * @return array<string, string>
     */
    private function whatsappLinks(array $tickets): array
    {
        $links = [];

        foreach ($tickets as $ticket) {
            if ($this->notifier->numberFor($ticket->getCompany()) === '') {
                continue;
            }

            foreach ($ticket->getMessages() as $message) {
                $id = $message->getId();

                if (! $message->isFromOwner() || $id === null) {
                    continue;
                }

                $url = $this->notifier->whatsappUrl($ticket, $message->getBody());

                if ($url !== null) {
                    $links[(string) $id] = $url;
                }
            }
        }

        return $links;
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

        if ($body === '') {
            $this->addFlash('success', 'Status updated.');

            return $this->redirectToRoute('_support_desk');
        }

        // Saying "Reply sent" and leaving it there was the whole problem: the
        // reply went into the portal and the member was never told about it. Say
        // exactly what left the building, and where it went.
        $notified = $this->notifier->ownerReplied($ticket, $body, $ticket->getStatus() === SupportTicketStatus::Closed);

        $this->addFlash(...match ($notified['outcome']) {
            SupportNotifier::SENT => ['success', sprintf('Reply saved, and emailed to %s.', $notified['address'])],
            SupportNotifier::NO_ADDRESS => ['warning', 'Reply saved. There is no email address on this ticket, so nobody was written to - use the WhatsApp button under your reply.'],
            default => ['warning', sprintf('Reply saved, but the email to %s could not go out. Use the WhatsApp button under your reply, and check Settings, System Settings, Email.', $notified['address'])],
        });

        return $this->redirectToRoute('_support_desk');
    }

    /**
     * The desk answers tickets from every business, so its queries run with the
     * company filter borrowed - see the trait for why it is suspended and not
     * disabled.
     */
    private function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }
}
