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
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\MarketplaceAd;
use SolidInvoice\CoreBundle\Marketplace\MediaStore;
use SolidInvoice\CoreBundle\Marketplace\MediaUploadFailed;
use SolidInvoice\CoreBundle\Membership\MembershipManager;
use SolidInvoice\CoreBundle\Repository\MarketplaceAdRepository;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function implode;
use function trim;

/**
 * A member's own classified advert.
 *
 * The four places on the Marketplace home page are sold by the platform owner,
 * so this page is only ever reachable by a business that has been given the
 * Classifieds button. What it does NOT do is put the advert on the page - the
 * member writes it and uploads the picture, and it sits here until the owner
 * gives it one of the four places from the desk. That is the whole reason the
 * places are worth paying for.
 */
#[IsGranted('ROLE_ADMIN')]
final class Classifieds extends AbstractController
{
    public function __construct(
        private readonly CompanySelector $companySelector,
        private readonly EntityManagerInterface $entityManager,
        private readonly MarketplaceAdRepository $ads,
        private readonly MediaStore $store,
        private readonly MembershipManager $membership,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $company = $this->currentCompany();

        if (! $company instanceof Company) {
            $this->addFlash('error', 'Pick a company first, then come back to this page.');

            return $this->redirectToRoute('_dashboard');
        }

        // Checked here rather than in the membership listener because this is not
        // something a plan buys - the owner sells it by name, so the answer lives
        // on the company and nowhere else.
        if (! $this->membership->hasClassifiedsAccess($company)) {
            $this->addFlash('error', 'Classified adverts are sold separately. Message us from the Support page and we will set one up for you.');

            return $this->redirectToRoute('_marketplace');
        }

        $ad = $this->ads->findForCompany($company)[0] ?? null;

        if ($request->isMethod('POST')) {
            return $this->handleSave($request, $company, $ad);
        }

        return $this->render('@SolidInvoiceCore/Marketplace/classifieds.html.twig', [
            'company' => $company,
            'ad' => $ad,
            'accept' => '.' . implode(',.', $this->store->allowedExtensions()),
            'maxMb' => (int) ($this->store->maxBytes() / 1024 / 1024),
        ]);
    }

    private function handleSave(Request $request, Company $company, ?MarketplaceAd $ad): Response
    {
        if (! $this->isCsrfTokenValid('marketplace.classified', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_marketplace_classifieds');
        }

        $title = trim((string) $request->request->get('title'));

        if ($title === '') {
            $this->addFlash('error', 'Please give the advert a headline.');

            return $this->redirectToRoute('_marketplace_classifieds');
        }

        if (! $ad instanceof MarketplaceAd) {
            $ad = new MarketplaceAd();
            $ad->setCompany($company);

            $user = $this->getUser();

            if ($user instanceof User) {
                $ad->setCreatedBy($user);
            }

            $this->entityManager->persist($ad);
        }

        $ad->setTitle($title)
            ->setCaption((string) $request->request->get('caption'))
            ->setLinkUrl($this->cleanLink((string) $request->request->get('link_url')));

        $file = $request->files->get('image');

        if ($file instanceof UploadedFile) {
            try {
                $path = $this->store->store($company, 'ad', $file);
            } catch (MediaUploadFailed $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('_marketplace_classifieds');
            }

            // The old picture goes only once the new one is safely written, so a
            // failed upload never leaves a paid advert with a blank space in it.
            $this->store->remove($ad->getImagePath());
            $ad->setImagePath($path);
        }

        $this->entityManager->flush();

        $this->addFlash(
            'success',
            $ad->getSlot() === null
                ? 'Saved. Your advert is with us - we will put it on the Marketplace shortly.'
                : 'Saved. Your advert is live on the Marketplace.'
        );

        return $this->redirectToRoute('_marketplace_classifieds');
    }

    /**
     * A link with no scheme is what people actually type. Left as "nmp.ae" the
     * browser reads it as a path on our own site and the advert leads nowhere.
     */
    private function cleanLink(string $link): string
    {
        $link = trim($link);

        if ($link === '') {
            return '';
        }

        if (str_starts_with($link, 'http://') || str_starts_with($link, 'https://')) {
            return $link;
        }

        return 'https://' . $link;
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
