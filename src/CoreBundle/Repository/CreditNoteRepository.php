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
use SolidInvoice\CoreBundle\Entity\CreditNote;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;

/**
 * @extends EntityRepository<CreditNote>
 */
class CreditNoteRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CreditNote::class);
    }

    /**
     * All credit notes for the current company (scoped by the CompanyFilter),
     * newest first.
     *
     * @return list<CreditNote>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('cn')
            ->orderBy('cn.creditDate', 'DESC')
            ->addOrderBy('cn.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Credit notes raised against a given invoice, newest first.
     *
     * @return list<CreditNote>
     */
    public function findForInvoice(Invoice $invoice): array
    {
        return $this->createQueryBuilder('cn')
            ->where('cn.invoice = :invoice')
            ->setParameter('invoice', $invoice)
            ->orderBy('cn.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * CASH refunds whose date falls within the given inclusive range, oldest
     * first. Used by the daily ledger as money-out (store credit is excluded -
     * it is not a cash movement).
     *
     * @return list<CreditNote>
     */
    public function findCashBetween(DateTimeInterface $start, DateTimeInterface $end): array
    {
        return $this->createQueryBuilder('cn')
            ->where('cn.creditDate BETWEEN :start AND :end')
            ->andWhere('cn.refundType = :cash')
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->setParameter('cash', CreditNote::TYPE_CASH)
            ->orderBy('cn.creditDate', 'ASC')
            ->addOrderBy('cn.created', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function delete(CreditNote $creditNote): void
    {
        $em = $this->getEntityManager();
        $em->remove($creditNote);
        $em->flush();
    }
}
