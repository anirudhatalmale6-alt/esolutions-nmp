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
use SolidInvoice\CoreBundle\Entity\CommunityComment;
use SolidInvoice\CoreBundle\Entity\CommunityPost;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Repository\CommunityPostRepository;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function mb_strlen;
use function mb_substr;
use function trim;

/**
 * A reply to somebody else's post.
 *
 * The post is looked up with the company filter off on purpose: replying to
 * another business is the entire point of a community feed, and the filter would
 * make every post but your own look as though it did not exist.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ReplyToPost extends AbstractController
{
    private const int MAX_BODY = 1000;

    public function __construct(
        private readonly CompanySelector $companySelector,
        private readonly EntityManagerInterface $entityManager,
        private readonly CommunityPostRepository $posts,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (! $this->isCsrfTokenValid('community.reply', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_marketplace');
        }

        $post = $this->posts->findOneForReading($id);

        if (! $post instanceof CommunityPost || $post->isHidden()) {
            $this->addFlash('error', 'That post is no longer there.');

            return $this->redirectToRoute('_marketplace');
        }

        $body = trim((string) $request->request->get('body'));

        if ($body === '') {
            return $this->redirectToRoute('_marketplace');
        }

        if (mb_strlen($body) > self::MAX_BODY) {
            $body = mb_substr($body, 0, self::MAX_BODY);
        }

        $comment = new CommunityComment();
        $comment->setBody($body);

        $company = $this->currentCompany();

        if ($company instanceof Company) {
            $comment->setCompany($company);
        }

        $user = $this->getUser();

        if ($user instanceof User) {
            $comment->setAuthor($user);
        }

        $post->addComment($comment);

        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        return $this->redirectToRoute('_marketplace');
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
