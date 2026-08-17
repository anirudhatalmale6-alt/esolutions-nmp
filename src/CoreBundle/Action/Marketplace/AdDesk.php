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
use SolidInvoice\CoreBundle\Marketplace\MediaUploadFailed;
use SolidInvoice\CoreBundle\Repository\MarketplaceAdRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function sprintf;
use function str_starts_with;
use function trim;

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

        $action = (string) $request->request->get('action');

        // Writing one is the only thing that does not start from an existing
        // advert, so it is handled before the lookup.
        if ($action === 'create') {
            return $this->create($request);
        }

        $ad = $this->resolveAd((string) $request->request->get('ad'));

        if (! $ad instanceof MarketplaceAd) {
            $this->addFlash('error', 'That advert could not be found.');

            return $this->redirectToRoute('_marketplace_ads');
        }

        return match ($action) {
            'place' => $this->place($request, $ad),
            'pull' => $this->pull($ad),
            'delete' => $this->delete($ad),
            'picture' => $this->replacePicture($request, $ad),
            default => $this->redirectToRoute('_marketplace_ads'),
        };
    }

    /**
     * The platform owner writing an advert himself.
     *
     * These belong to nobody - no company on the row - which is what makes them
     * the portal's own: they keep showing whatever any member's membership is
     * doing, so an empty place can always be filled with something rather than
     * left blank.
     */
    private function create(Request $request): Response
    {
        $title = trim((string) $request->request->get('title'));

        if ($title === '') {
            $this->addFlash('error', 'Give the advert a headline first.');

            return $this->redirectToRoute('_marketplace_ads');
        }

        $ad = new MarketplaceAd();
        $ad->setTitle($title)
            ->setCaption((string) $request->request->get('caption'))
            ->setLinkUrl($this->cleanLink((string) $request->request->get('link_url')));

        $file = $request->files->get('image');

        if (! $file instanceof UploadedFile) {
            $this->addFlash('error', 'Choose a picture for the advert - it is the whole thing people look at.');

            return $this->redirectToRoute('_marketplace_ads');
        }

        try {
            $ad->setImagePath($this->store->store(null, 'ad', $file));
        } catch (MediaUploadFailed $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('_marketplace_ads');
        }

        $slot = $request->request->getInt('slot');

        if ($slot >= 1 && $slot <= MarketplaceAd::SLOTS) {
            $this->entityManager->persist($ad);
            $this->entityManager->flush();

            // Persisted first so it has an id to keep itself out of the clear.
            $this->ads->clearSlot($slot, $ad);
            $ad->setSlot($slot);
        } else {
            $this->entityManager->persist($ad);
        }

        $this->entityManager->flush();

        $this->addFlash(
            'success',
            $ad->getSlot() === null
                ? sprintf('"%s" is saved. Give it one of the four places when you are ready.', $ad->getTitle())
                : sprintf('"%s" is now in place %d on the Marketplace.', $ad->getTitle(), $ad->getSlot())
        );

        return $this->redirectToRoute('_marketplace_ads');
    }

    /**
     * Swap the picture on an advert - the member's or one of ours - without
     * making somebody rewrite the rest of it.
     */
    private function replacePicture(Request $request, MarketplaceAd $ad): Response
    {
        $file = $request->files->get('image');

        if (! $file instanceof UploadedFile) {
            $this->addFlash('error', 'Choose a picture to upload.');

            return $this->redirectToRoute('_marketplace_ads');
        }

        try {
            $path = $this->store->store($ad->getCompany(), 'ad', $file);
        } catch (MediaUploadFailed $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('_marketplace_ads');
        }

        // The old one goes only once the new one is safely written.
        $this->store->remove($ad->getImagePath());
        $ad->setImagePath($path);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('New picture on "%s".', $ad->getTitle()));

        return $this->redirectToRoute('_marketplace_ads');
    }

    /**
     * A link with no scheme is what people actually type. Left as "nmp.ae" the
     * browser reads it as a path on our own site and the advert leads nowhere.
     */
    private function cleanLink(string $link): string
    {
        $link = trim($link);

        if ($link === '' || str_starts_with($link, 'http://') || str_starts_with($link, 'https://')) {
            return $link;
        }

        return 'https://' . $link;
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
