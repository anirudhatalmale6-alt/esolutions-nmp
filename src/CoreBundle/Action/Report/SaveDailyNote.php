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

namespace SolidInvoice\CoreBundle\Action\Report;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\DailyNote;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Repository\DailyNoteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;
use function method_exists;
use function trim;

/**
 * Saves the day's scrap note from the daily ledger.
 *
 * One note per day: saving again overwrites that day's text rather than piling
 * up rows, which is how a notepad page behaves. Clearing the box empties the
 * note instead of deleting the row, so the "last edited by" trail survives.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class SaveDailyNote extends AbstractController
{
    public function __construct(
        private readonly DailyNoteRepository $noteRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
        private readonly Security $security,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $date = $this->resolveDate((string) $request->request->get('date', ''));
        $redirect = $this->redirectToRoute('_daily_ledger', ['date' => $date->format('Y-m-d')]);

        if (! $this->isCsrfTokenValid('daily.note', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try saving the note again.');

            return $redirect;
        }

        $companyId = $this->companySelector->getCompany();
        $company = $companyId !== null ? $this->companyRepository->find($companyId) : null;

        if (! $company instanceof Company) {
            $this->addFlash('error', 'No active company selected.');

            return $redirect;
        }

        $note = $this->noteRepository->findForDate($date);

        if (! $note instanceof DailyNote) {
            $note = new DailyNote();
            $note->setCompany($company)
                ->setNoteDate($date);

            $this->entityManager->persist($note);
        }

        $body = trim((string) $request->request->get('body', ''));

        $note->setBody($body === '' ? null : $body)
            ->setUpdatedBy($this->currentUserName());

        $this->entityManager->flush();

        $this->addFlash('success', $body === '' ? 'Note cleared.' : 'Note saved.');

        return $redirect;
    }

    private function resolveDate(string $value): DateTimeImmutable
    {
        if (trim($value) === '') {
            return new DateTimeImmutable('today');
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return new DateTimeImmutable('today');
        }
    }

    private function currentUserName(): ?string
    {
        $user = $this->security->getUser();

        if ($user === null) {
            return null;
        }

        return method_exists($user, 'getUsername') ? (string) $user->getUsername() : $user->getUserIdentifier();
    }
}
