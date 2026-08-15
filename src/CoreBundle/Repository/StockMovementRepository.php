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
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\StockModel;
use SolidInvoice\CoreBundle\Entity\StockMovement;
use SolidInvoice\CoreBundle\Enum\StockMovementReason;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function is_string;
use function strlen;

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
            ->setParameter('model', $model->getId(), UlidType::NAME)
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
     * How many real movements a company has recorded - everything except the
     * opening figure itself.
     *
     * A count above zero means the system, not the Tally sheet, now knows what
     * is on the shelf; see {@see \SolidInvoice\CoreBundle\Stock\StockAlreadyLiveException}.
     */
    public function countLiveMovementsForCompany(Company $company): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->join('m.stockModel', 'model')
            ->where('model.company = :company')
            ->andWhere('m.reason != :baseline')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->setParameter('baseline', StockMovementReason::Baseline->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The stock history for the business the user is signed into, newest first,
     * capped so a busy vendor's page stays fast. Company scoping comes from the
     * CompanyFilter.
     *
     * @return list<StockMovement>
     */
    public function findRecent(?StockModel $model = null, int $limit = 300): array
    {
        $qb = $this->createQueryBuilder('m')
            ->join('m.stockModel', 'model')
            ->addSelect('model')
            ->orderBy('m.movedAt', 'DESC')
            ->addOrderBy('m.created', 'DESC')
            ->setMaxResults($limit);

        if ($model instanceof StockModel) {
            $qb->where('m.stockModel = :model')
                ->setParameter('model', $model->getId(), UlidType::NAME);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * What a document has already put through the ledger, per stock item.
     *
     * The posting side compares this against what the document says today and
     * writes only the difference, so saving the same invoice twice moves nothing
     * the second time.
     *
     * @return array<string, int> stock model id => net quantity already posted
     */
    public function netBySourceGroupedByModel(string $sourceType, string $sourceId): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.stockModel) AS model_id', 'SUM(m.quantity) AS net')
            ->where('m.sourceType = :type')
            ->andWhere('m.sourceId = :id')
            ->setParameter('type', $sourceType)
            ->setParameter('id', $sourceId)
            ->groupBy('m.stockModel')
            ->getQuery()
            ->getArrayResult();

        $net = [];

        foreach ($rows as $row) {
            $modelId = self::normaliseId($row['model_id']);

            if ($modelId === null) {
                continue;
            }

            $net[$modelId] = (int) $row['net'];
        }

        return $net;
    }

    /**
     * IDENTITY() hands the foreign key back as whatever the driver produced -
     * raw 16 bytes, a hex string, or an already-converted Ulid. Bring all three
     * to the one canonical string so the map keys line up with the ids the
     * posting side works with.
     */
    private static function normaliseId(mixed $value): ?string
    {
        if ($value instanceof Ulid) {
            return (string) $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (Ulid::isValid($value)) {
            return (string) Ulid::fromString($value);
        }

        return strlen($value) === 16 ? (string) Ulid::fromBinary($value) : null;
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
            // Bound as the id with its type spelled out. A Ulid primary key is
            // 16 raw bytes in the column; left to infer the type from the
            // entity, the comparison goes in as the readable 26-character form
            // and quietly matches nothing - which reads as "no history" rather
            // than as an error.
            ->setParameter('model', $model->getId(), UlidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($sum ?? 0);
    }
}
