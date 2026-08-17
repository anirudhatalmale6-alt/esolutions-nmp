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

namespace SolidInvoice\CoreBundle\Action\Community;

use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\CommunityPost;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Repository\CommunityPostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Takes a post off the feed.
 *
 * Two people may do it and nobody else: the business that wrote it, and the
 * platform owner. It is hidden rather than deleted, so if a member argues about
 * why their post went, the owner can still read what it said.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class RemovePost extends AbstractController
{
    public function __construct(
        private readonly CompanySelector $companySelector,
        private readonly EntityManagerInterface $entityManager,
        private readonly CommunityPostRepository $posts,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (! $this->isCsrfTokenValid('community.remove', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_marketplace');
        }

        $post = $this->posts->findOneForReading($id);

        if (! $post instanceof CommunityPost) {
            return $this->redirectToRoute('_marketplace');
        }

        if (! $this->mayRemove($post)) {
            $this->addFlash('error', 'That is not your post.');

            return $this->redirectToRoute('_marketplace');
        }

        $post->setHidden(true);
        $this->entityManager->flush();

        $this->addFlash('success', 'Post removed.');

        return $this->redirectToRoute('_marketplace');
    }

    /**
     * Compared by id rather than by object: the post's company and the reader's
     * come from two different queries, so they are not the same instance even
     * when they are the same business.
     */
    private function mayRemove(CommunityPost $post): bool
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }

        $companyId = $this->companySelector->getCompany();

        if ($companyId === null) {
            return false;
        }

        $company = $post->getCompany();

        return $company instanceof Company && $company->getId()?->equals($companyId) === true;
    }
}
