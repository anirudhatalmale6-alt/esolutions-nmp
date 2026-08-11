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
use SolidInvoice\CoreBundle\Entity\StockModel;
use SolidInvoice\CoreBundle\Entity\StockMovement;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;

/**
 * @extends EntityRepository<StockMovement>
 */
class StockMovementRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockMovement::class);
    }

    /**
     * The full history for one model, newest first.
     *
     * @return list<StockMovement>
     */
    public function findForModel(StockModel $model): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.stockModel = :model')
            ->setParameter('model', $model)
            ->orderBy('m.movedAt', 'DESC')
            ->addOrderBy('m.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Movements in a date range, newest first - the stock equivalent of the
     * daily ledger.
     *
     * @return list<StockMovement>
     */
    public function findBetween(DateTimeInterface $start, DateTimeInterface $end): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.movedAt BETWEEN :start AND :end')
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->orderBy('m.movedAt', 'DESC')
            ->addOrderBy('m.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every movement recorded against a given source document. Used to reverse
     * a document's effect on stock when it is cancelled or deleted, without
     * guessing at quantities.
     *
     * @return list<StockMovement>
     */
    public function findForSource(string $sourceType, string $sourceId): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.sourceType = :type')
            ->andWhere('m.sourceId = :id')
            ->setParameter('type', $sourceType)
            ->setParameter('id', $sourceId)
            ->getQuery()
            ->getResult();
    }

    /**
     * The net of every movement for a model - what its quantity SHOULD be.
     * The running figure on StockModel is what it actually is; comparing the two
     * catches drift.
     */
    public function netQuantityForModel(StockModel $model): int
    {
        $sum = $this->createQueryBuilder('m')
            ->select('SUM(m.quantity)')
            ->where('m.stockModel = :model')
            ->setParameter('model', $model)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($sum ?? 0);
    }
}
