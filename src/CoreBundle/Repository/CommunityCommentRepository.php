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

namespace SolidInvoice\CoreBundle\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\Persistence\ManagerRegistry;
use SolidInvoice\CoreBundle\Entity\CommunityComment;
use SolidInvoice\CoreBundle\Entity\CommunityPost;
use SolidInvoice\CoreBundle\Repository\Traits\WithoutCompanyFilter;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function array_map;

/**
 * @extends EntityRepository<CommunityComment>
 */
class CommunityCommentRepository extends EntityRepository
{
    use WithoutCompanyFilter;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommunityComment::class);
    }

    /**
     * The replies to a post, oldest first - a conversation reads downwards.
     *
     * Loaded through here rather than off the post's collection so the company
     * filter can be taken off: a reply from another business is the entire point
     * of a community feed, and with the filter on every post would look as if
     * nobody had answered it.
     *
     * @return list<CommunityComment>
     */
    public function findForPost(CommunityPost $post): array
    {
        $id = $post->getId();

        if (! $id instanceof Ulid) {
            return [];
        }

        return $this->withoutCompanyFilter(fn (): array => $this->createQueryBuilder('c')
            ->addSelect('co')
            ->leftJoin('c.company', 'co')
            ->where('c.post = :post')
            ->andWhere('c.hidden = :hidden')
            ->setParameter('post', $id, UlidType::NAME)
            ->setParameter('hidden', false)
            ->orderBy('c.created', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The replies to a whole page of posts in one query, keyed by post id.
     *
     * The feed shows a handful of replies under every post; asking per post
     * turns one page into thirty queries.
     *
     * @param list<CommunityPost> $posts
     * @return array<string, list<CommunityComment>>
     */
    public function findForPosts(array $posts): array
    {
        $ids = [];

        foreach ($posts as $post) {
            $id = $post->getId();

            if ($id instanceof Ulid) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        // Ulids go into an IN() as the database sees them, not as text. Left as
        // 26-character strings they are compared against a BINARY(16) column and
        // match nothing, so every post would look as though nobody had replied.
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();
        $ulidType = Type::getType(UlidType::NAME);
        $converted = array_map(
            static fn (Ulid $id) => $ulidType->convertToDatabaseValue($id, $platform),
            $ids,
        );

        /** @var list<CommunityComment> $comments */
        $comments = $this->withoutCompanyFilter(fn (): array => $this->createQueryBuilder('c')
            ->addSelect('co')
            ->leftJoin('c.company', 'co')
            ->where('c.post IN (:posts)')
            ->andWhere('c.hidden = :hidden')
            ->setParameter('posts', $converted, ArrayParameterType::STRING)
            ->setParameter('hidden', false)
            ->orderBy('c.created', 'ASC')
            ->getQuery()
            ->getResult());

        $grouped = [];

        foreach ($comments as $comment) {
            $postId = $comment->getPost()?->getId();

            if ($postId instanceof Ulid) {
                $grouped[$postId->toString()][] = $comment;
            }
        }

        return $grouped;
    }
}
