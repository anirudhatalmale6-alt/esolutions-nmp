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

use Doctrine\Persistence\ManagerRegistry;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\StoreOrder;
use SolidInvoice\CoreBundle\Enum\OrderStatus;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use function sprintf;
use function str_pad;
use const STR_PAD_LEFT;

/**
 * @extends EntityRepository<StoreOrder>
 */
class StoreOrderRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoreOrder::class);
    }

    /**
     * All orders for the current company (CompanyFilter scopes automatically),
     * newest first. ULIDs sort chronologically so ordering by id is reliable
     * without depending on the created timestamp.
     *
     * @return list<StoreOrder>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('o')
            ->orderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Orders for the current company filtered to a single status, newest first.
     *
     * @return list<StoreOrder>
     */
    public function findByStatusOrdered(OrderStatus $status): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.status = :status')
            ->setParameter('status', $status->value)
            ->orderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count per status for the current company, keyed by status value. Used to
     * show the counts on the status filter bar.
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('o.status AS status, COUNT(o.id) AS total')
            ->groupBy('o.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * The next running order number for a company, e.g. MO-0001. Scopes by
     * company explicitly so it is correct regardless of the request context.
     */
    public function nextOrderNumber(Company $company): string
    {
        $count = (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('MO-%s', str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT));
    }
}
