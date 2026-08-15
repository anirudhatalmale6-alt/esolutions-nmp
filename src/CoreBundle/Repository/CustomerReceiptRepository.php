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
use SolidInvoice\ClientBundle\Entity\Client;
use SolidInvoice\CoreBundle\Entity\CustomerReceipt;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * @extends EntityRepository<CustomerReceipt>
 */
class CustomerReceiptRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerReceipt::class);
    }

    /**
     * All receipts for the current company (scoped by the CompanyFilter),
     * newest first.
     *
     * @return list<CustomerReceipt>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.receiptDate', 'DESC')
            ->addOrderBy('r.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Receipts whose date falls within the given inclusive range, oldest first.
     * Used by the daily ledger (money in) report.
     *
     * @return list<CustomerReceipt>
     */
    public function findBetween(DateTimeInterface $start, DateTimeInterface $end): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.receiptDate BETWEEN :start AND :end')
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->orderBy('r.receiptDate', 'ASC')
            ->addOrderBy('r.created', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Total received from one client across all their receipts (major units, as a
     * plain string) - used to reduce that client's outstanding balance.
     */
    public function totalForClient(Client $client): string
    {
        $sum = $this->createQueryBuilder('r')
            ->select('SUM(r.amount)')
            ->where('r.client = :client')
            ->setParameter('client', $client->getId(), UlidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();

        return (string) ($sum ?? '0');
    }

    public function delete(CustomerReceipt $receipt): void
    {
        $em = $this->getEntityManager();
        $em->remove($receipt);
        $em->flush();
    }
}
