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

namespace SolidInvoice\CoreBundle\Action\CreditNote;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use SolidInvoice\ClientBundle\Entity\Client;
use SolidInvoice\ClientBundle\Repository\CreditRepository;
use SolidInvoice\CoreBundle\Entity\CreditNote;
use SolidInvoice\CoreBundle\Repository\CreditNoteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DeleteCreditNote extends AbstractController
{
    public function __construct(
        private readonly CreditNoteRepository $creditNoteRepository,
        private readonly CreditRepository $creditRepository,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (! $this->isCsrfTokenValid('creditnote.delete', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_credit_notes_list');
        }

        if (Ulid::isValid($id)) {
            $creditNote = $this->creditNoteRepository->find(Ulid::fromString($id));

            if ($creditNote instanceof CreditNote) {
                // A store-credit note added to the client's balance, so removing it
                // must take that credit back off, otherwise the balance is inflated.
                $client = $creditNote->getClient();

                if ($creditNote->isStoreCredit() && $client instanceof Client) {
                    $minor = (string) BigDecimal::of($creditNote->getAmount())
                        ->multipliedBy(100)
                        ->toScale(0, RoundingMode::HalfUp);
                    $this->creditRepository->deductCredit($client, $minor);
                }

                $this->creditNoteRepository->delete($creditNote);
                $this->addFlash('success', 'Credit note deleted.');
            }
        }

        return $this->redirectToRoute('_credit_notes_list');
    }
}
