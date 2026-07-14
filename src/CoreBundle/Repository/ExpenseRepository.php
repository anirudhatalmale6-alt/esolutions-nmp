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
use SolidInvoice\CoreBundle\Entity\Expense;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;

/**
 * @extends EntityRepository<Expense>
 */
class ExpenseRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Expense::class);
    }

    /**
     * All expenses for the current company (scoped by the CompanyFilter),
     * newest first.
     *
     * @return list<Expense>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.expenseDate', 'DESC')
            ->addOrderBy('e.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Expenses whose date falls within the given inclusive range, oldest first.
     * Used by the daily ledger report.
     *
     * @return list<Expense>
     */
    public function findBetween(DateTimeInterface $start, DateTimeInterface $end): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.expenseDate BETWEEN :start AND :end')
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->orderBy('e.expenseDate', 'ASC')
            ->addOrderBy('e.created', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function delete(Expense $expense): void
    {
        $em = $this->getEntityManager();
        $em->remove($expense);
        $em->flush();
    }
}
