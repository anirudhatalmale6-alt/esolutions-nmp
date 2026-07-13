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
use SolidInvoice\CoreBundle\Entity\Purchase;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;

/**
 * @extends EntityRepository<Purchase>
 */
class PurchaseRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Purchase::class);
    }

    /**
     * All purchases for the current company (scoped by the CompanyFilter),
     * supplier eager-loaded, newest first.
     *
     * @return list<Purchase>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.supplier', 's')
            ->addSelect('s')
            ->orderBy('p.purchaseDate', 'DESC')
            ->addOrderBy('p.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function delete(Purchase $purchase): void
    {
        $em = $this->getEntityManager();
        $em->remove($purchase);
        $em->flush();
    }
}
