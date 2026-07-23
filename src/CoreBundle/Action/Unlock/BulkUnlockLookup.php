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

namespace SolidInvoice\CoreBundle\Action\Unlock;

use SolidInvoice\CoreBundle\Repository\UnlockCodeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function array_key_exists;
use function preg_match;
use function preg_replace;
use function preg_split;

/**
 * Internal bulk lookup: paste a batch of IMEIs (from an order, however they are
 * separated) and get every code back in one go, ready to copy and forward.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class BulkUnlockLookup extends AbstractController
{
    public function __construct(
        private readonly UnlockCodeRepository $unlockCodeRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('unlock.lookup', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try the lookup again.');

            return $this->redirectToRoute('_unlock_list');
        }

        $raw = (string) $request->request->get('imeis', '');

        // Split on anything that is not a digit, so newlines, spaces, commas,
        // tabs - whatever the owner pastes - all work.
        $tokens = preg_split('/\D+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $imeis = [];
        foreach ($tokens as $token) {
            $digits = (string) preg_replace('/\D+/', '', $token);
            if (preg_match('/^\d{14,17}$/', $digits) === 1 && ! array_key_exists($digits, $imeis)) {
                $imeis[$digits] = true;
            }
        }
        $imeis = array_keys($imeis);

        $map = $this->unlockCodeRepository->findByImeis($imeis);

        $results = [];
        $found = 0;
        foreach ($imeis as $imei) {
            $code = array_key_exists($imei, $map) ? $map[$imei]->getCode() : null;
            if ($code !== null) {
                ++$found;
            }
            $results[] = ['imei' => $imei, 'code' => $code];
        }

        return $this->render('@SolidInvoiceCore/Unlock/lookup.html.twig', [
            'raw' => $raw,
            'results' => $results,
            'found' => $found,
            'total' => count($imeis),
        ]);
    }
}
