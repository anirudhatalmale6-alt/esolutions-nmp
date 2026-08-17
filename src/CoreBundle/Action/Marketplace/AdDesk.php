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

namespace SolidInvoice\CoreBundle\Action\Marketplace;

use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Entity\MarketplaceAd;
use SolidInvoice\CoreBundle\Marketplace\MediaStore;
use SolidInvoice\CoreBundle\Repository\MarketplaceAdRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function sprintf;

/**
 * Where the platform owner decides which four adverts are on the Marketplace.
 *
 * Members write their own adverts; nothing appears on the page until it is given
 * one of the four places from here. Putting an advert into a place that is
 * already taken moves the other one out rather than drawing them on top of each
 * other - two adverts in one place is not a state anybody can look at and
 * understand.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class AdDesk extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MarketplaceAdRepository $ads,
        private readonly MediaStore $store,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handle($request);
        }

        $all = $this->ads->findAllForOwner();

        $placed = [];

        foreach ($all as $ad) {
            $slot = $ad->getSlot();

            if ($slot !== null) {
                $placed[$slot] = $ad;
            }
        }

        $slots = [];

        for ($slot = 1; $slot <= MarketplaceAd::SLOTS; ++$slot) {
            $slots[$slot] = $placed[$slot] ?? null;
        }

        return $this->render('@SolidInvoiceCore/Marketplace/ad_desk.html.twig', [
            'ads' => $all,
            'slots' => $slots,
            'slotCount' => MarketplaceAd::SLOTS,
        ]);
    }

    private function handle(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('marketplace.ads', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_marketplace_ads');
        }

        $ad = $this->resolveAd((string) $request->request->get('ad'));

        if (! $ad instanceof MarketplaceAd) {
            $this->addFlash('error', 'That advert could not be found.');

            return $this->redirectToRoute('_marketplace_ads');
        }

        return match ((string) $request->request->get('action')) {
            'place' => $this->place($request, $ad),
            'pull' => $this->pull($ad),
            'delete' => $this->delete($ad),
            default => $this->redirectToRoute('_marketplace_ads'),
        };
    }

    private function place(Request $request, MarketplaceAd $ad): Response
    {
        $slot = $request->request->getInt('slot');

        if ($slot < 1 || $slot > MarketplaceAd::SLOTS) {
            $this->addFlash('error', sprintf('Pick a place between 1 and %d.', MarketplaceAd::SLOTS));

            return $this->redirectToRoute('_marketplace_ads');
        }

        if ($ad->getImagePath() === null) {
            $this->addFlash('error', 'That advert has no picture yet, so there is nothing to show. Ask them to upload one first.');

            return $this->redirectToRoute('_marketplace_ads');
        }

        // Whoever was in this place steps out first. Done before the flush so the
        // page never has a moment with two adverts claiming the same place.
        $this->ads->clearSlot($slot, $ad);

        $ad->setSlot($slot)->setActive(true);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('"%s" is now in place %d on the Marketplace.', $ad->getTitle(), $slot));

        return $this->redirectToRoute('_marketplace_ads');
    }

    private function pull(MarketplaceAd $ad): Response
    {
        $ad->setSlot(null);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('"%s" is off the Marketplace. What they wrote is still here.', $ad->getTitle()));

        return $this->redirectToRoute('_marketplace_ads');
    }

    private function delete(MarketplaceAd $ad): Response
    {
        $title = $ad->getTitle();

        // The picture goes with the row. Nothing else points at it, so leaving it
        // behind would only fill the disk with files nobody can reach.
        $this->store->remove($ad->getImagePath());

        $this->entityManager->remove($ad);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('"%s" has been deleted.', $title));

        return $this->redirectToRoute('_marketplace_ads');
    }

    private function resolveAd(string $id): ?MarketplaceAd
    {
        return $this->ads->findOneForOwner($id);
    }
}
