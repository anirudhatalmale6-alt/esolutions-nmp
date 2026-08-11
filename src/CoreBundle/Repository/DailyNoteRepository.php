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

use DateTimeInterface;
use Doctrine\Persistence\ManagerRegistry;
use SolidInvoice\CoreBundle\Entity\DailyNote;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;

/**
 * @extends EntityRepository<DailyNote>
 */
class DailyNoteRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyNote::class);
    }

    /**
     * The note for one day, if there is one. Company scoping comes from the
     * CompanyFilter, same as everywhere else.
     */
    public function findForDate(DateTimeInterface $date): ?DailyNote
    {
        return $this->createQueryBuilder('n')
            ->where('n.noteDate = :date')
            ->setParameter('date', $date->format('Y-m-d'))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Dates that actually have something written on them, newest first - so the
     * ledger can offer a list of past notes to flip back through.
     *
     * @return list<DailyNote>
     */
    public function findRecent(int $limit = 30): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.body IS NOT NULL')
            ->andWhere("n.body != ''")
            ->orderBy('n.noteDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
