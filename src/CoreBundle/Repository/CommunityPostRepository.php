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

use SolidInvoice\CoreBundle\Entity\CommunityPost;
use SolidInvoice\CoreBundle\Repository\Traits\WithoutCompanyFilter;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends EntityRepository<CommunityPost>
 */
class CommunityPostRepository extends EntityRepository
{
    use WithoutCompanyFilter;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommunityPost::class);
    }

    /**
     * The feed, newest first, with the company filter off - every member reads
     * every post, and the Marketplace page is often read with nobody signed in
     * at all.
     *
     * Capped rather than paged for now: the page is one screen a buyer scrolls,
     * not an archive.
     *
     * @return list<CommunityPost>
     */
    public function findFeed(int $limit = 30): array
    {
        return $this->withoutCompanyFilter(fn (): array => $this->createQueryBuilder('p')
            ->addSelect('c')
            ->leftJoin('p.company', 'c')
            ->where('p.hidden = :hidden')
            ->setParameter('hidden', false)
            ->orderBy('p.created', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult());
    }

    /**
     * One post by id, whoever wrote it. Used when somebody replies to it or the
     * owner takes it down, both of which cross company lines by definition.
     */
    public function findOneForReading(string $id): ?CommunityPost
    {
        if (! Ulid::isValid($id)) {
            return null;
        }

        $ulid = Ulid::fromString($id);

        return $this->withoutCompanyFilter(fn (): ?CommunityPost => $this->createQueryBuilder('p')
            ->where('p.id = :id')
            ->setParameter('id', $ulid, UlidType::NAME)
            ->getQuery()
            ->getOneOrNullResult());
    }
}
