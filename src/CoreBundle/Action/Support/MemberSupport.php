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
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\SupportMessage;
use SolidInvoice\CoreBundle\Entity\SupportTicket;
use SolidInvoice\CoreBundle\Enum\SupportTicketKind;
use SolidInvoice\CoreBundle\Repository\SupportTicketRepository;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function mb_substr;
use function trim;

/**
 * "Tell us about it" - the member's side of the support desk.
 *
 * Until this existed there was no way to report a broken page from inside the
 * product: you had to already have the owner's phone number. That is fine while
 * everybody knows each other and stops being fine immediately after.
 *
 * Logged in only, by design - it is a conversation between a known business and
 * the platform owner, not a public contact form, so there is nothing here for a
 * stranger to abuse.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class MemberSupport extends AbstractController
{
    private const int SUBJECT_MAX = 191;

    private const int BODY_MAX = 5000;

    public function __construct(
        private readonly CompanySelector $companySelector,
        private readonly EntityManagerInterface $entityManager,
        private readonly SupportTicketRepository $tickets,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $company = $this->currentCompany();

        if (! $company instanceof Company) {
            $this->addFlash('error', 'Pick a company first, then come back to this page.');

            return $this->redirectToRoute('_dashboard');
        }

        if ($request->isMethod('POST')) {
            return $this->handlePost($request, $company);
        }

        $tickets = $this->tickets->findForCompany($company);

        // Opening the list is as good as reading the replies on it - there is
        // nothing else on this page to open.
        $marked = false;

        foreach ($tickets as $ticket) {
            if ($ticket->isUnreadByMember()) {
                $ticket->markReadByMember();
                $marked = true;
            }
        }

        if ($marked) {
            $this->entityManager->flush();
        }

        return $this->render('@SolidInvoiceCore/Support/index.html.twig', [
            'tickets' => $tickets,
            'kinds' => SupportTicketKind::cases(),
            'subjectMax' => self::SUBJECT_MAX,
            'bodyMax' => self::BODY_MAX,
        ]);
    }

    private function handlePost(Request $request, Company $company): Response
    {
        if (! $this->isCsrfTokenValid('support.member', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_support');
        }

        return match ((string) $request->request->get('intent', 'open')) {
            'reply' => $this->handleReply($request, $company),
            default => $this->handleOpen($request, $company),
        };
    }

    private function handleOpen(Request $request, Company $company): Response
    {
        $subject = trim((string) $request->request->get('subject', ''));
        $body = trim((string) $request->request->get('body', ''));

        if ($subject === '' || $body === '') {
            $this->addFlash('error', 'Please give it a short title and tell us what happened.');

            return $this->redirectToRoute('_support');
        }

        $ticket = new SupportTicket();
        $ticket
            ->setCompany($company)
            ->setSubject(mb_substr($subject, 0, self::SUBJECT_MAX))
            ->setKind(SupportTicketKind::fromValue((string) $request->request->get('kind')));

        $user = $this->getUser();

        if ($user instanceof User) {
            $ticket->setRaisedBy($user);
        }

        $ticket->addMessage($this->message($body, false));

        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        $this->addFlash('success', 'Thank you - we have it. You will see the reply on this page.');

        return $this->redirectToRoute('_support');
    }

    private function handleReply(Request $request, Company $company): Response
    {
        $ticket = $this->entityManager->find(SupportTicket::class, (string) $request->request->get('ticket'));

        // A member may only ever add to their own company's tickets. Checking the
        // company on the ticket rather than trusting the id in the form is the
        // whole of the access control here.
        if (! $ticket instanceof SupportTicket || $ticket->getCompany()?->getId()?->equals($company->getId()) !== true) {
            $this->addFlash('error', 'That conversation could not be found.');

            return $this->redirectToRoute('_support');
        }

        $body = trim((string) $request->request->get('body', ''));

        if ($body === '') {
            $this->addFlash('error', 'Please write something before sending.');

            return $this->redirectToRoute('_support');
        }

        $ticket->addMessage($this->message($body, false));
        $this->entityManager->flush();

        $this->addFlash('success', 'Sent.');

        return $this->redirectToRoute('_support');
    }

    private function message(string $body, bool $fromOwner): SupportMessage
    {
        $message = new SupportMessage();
        $message
            ->setBody(mb_substr($body, 0, self::BODY_MAX))
            ->setFromOwner($fromOwner);

        $user = $this->getUser();

        if ($user instanceof User) {
            $message->setAuthor($user);
        }

        return $message;
    }

    private function currentCompany(): ?Company
    {
        $companyId = $this->companySelector->getCompany();

        if ($companyId === null) {
            return null;
        }

        return $this->entityManager->find(Company::class, $companyId);
    }
}
