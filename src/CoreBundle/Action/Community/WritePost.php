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
use SolidInvoice\CoreBundle\Marketplace\MediaStore;
use SolidInvoice\CoreBundle\Marketplace\MediaUploadFailed;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function mb_strlen;
use function mb_substr;
use function trim;

/**
 * A member saying something to the rest of the market.
 *
 * Open to every signed-in member, not only Premium ones and not only the ones
 * paying for an advert - a feed nobody may write in is a noticeboard, and the
 * whole reason people were interested in this was seeing each other's stock.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class WritePost extends AbstractController
{
    /** Long enough for a stock list, short enough to stay a post. */
    private const int MAX_BODY = 2000;

    public function __construct(
        private readonly CompanySelector $companySelector,
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaStore $store,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('community.post', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try posting again.');

            return $this->redirectToRoute('_marketplace');
        }

        $company = $this->currentCompany();

        if (! $company instanceof Company) {
            $this->addFlash('error', 'Pick a company first, then post again.');

            return $this->redirectToRoute('_marketplace');
        }

        $body = trim((string) $request->request->get('body'));

        $files = $request->files->all('images');
        $files = is_array($files) ? $files : [];

        if ($body === '' && $files === []) {
            $this->addFlash('error', 'Write something, or add a picture.');

            return $this->redirectToRoute('_marketplace');
        }

        if (mb_strlen($body) > self::MAX_BODY) {
            $body = mb_substr($body, 0, self::MAX_BODY);
        }

        $post = new CommunityPost();
        $post->setCompany($company)
            ->setBody($body);

        $user = $this->getUser();

        if ($user instanceof User) {
            $post->setAuthor($user);
        }

        $images = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            if (count($images) >= CommunityPost::MAX_IMAGES) {
                break;
            }

            try {
                $images[] = $this->store->store($company, 'post', $file);
            } catch (MediaUploadFailed $e) {
                // Everything already written is thrown away rather than left on
                // disk with nothing pointing at it - the post itself was never
                // saved, so those files could never be reached again.
                foreach ($images as $path) {
                    $this->store->remove($path);
                }

                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('_marketplace');
            }
        }

        $post->setImages($images)->touch();

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->addFlash('success', 'Posted.');

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
