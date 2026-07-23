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

use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\UnlockCode;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use function preg_replace;

/**
 * @extends EntityRepository<UnlockCode>
 */
class UnlockCodeRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnlockCode::class);
    }

    /**
     * The current company's unlock codes, newest first. Company scoping is
     * applied automatically by the CompanyFilter.
     *
     * @return list<UnlockCode>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.updated', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A small sample of the most recently imported codes, for the admin preview.
     *
     * @return list<UnlockCode>
     */
    public function findRecent(int $limit = 8): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.updated', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countForCompany(Company $company): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Every unlock code for a company, keyed by IMEI, so an import can upsert in
     * memory instead of running a query per row.
     *
     * @return array<string, UnlockCode>
     */
    public function findMapForCompany(Company $company): array
    {
        $map = [];

        foreach ($this->createQueryBuilder('u')
            ->where('u.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getResult() as $entry) {
            $map[$entry->getImei()] = $entry;
        }

        return $map;
    }

    /**
     * Public IMEI lookup. Runs on an anonymous request where the company filter
     * adds no constraint, so it finds the code no matter which company owns it.
     * The IMEI is reduced to digits on both sides so spacing/formatting typed by
     * the customer does not matter.
     */
    public function findOneByImeiPublic(string $imei): ?UnlockCode
    {
        $digits = (string) preg_replace('/\D+/', '', $imei);

        if ($digits === '') {
            return null;
        }

        return $this->createQueryBuilder('u')
            ->where('u.imei = :imei')
            ->setParameter('imei', $digits)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Remove every unlock code belonging to the given company (a full reset).
     * Deletes straight through the DBAL connection by binary company_id, so it
     * bypasses the ORM company SQL-filter reliably.
     *
     * @return int the number of codes removed
     */
    public function deleteForCompany(Company $company): int
    {
        $companyId = $company->getId()?->toBinary();

        if ($companyId === null) {
            return 0;
        }

        $connection = $this->getEntityManager()->getConnection();

        return (int) $connection->executeStatement(
            'DELETE FROM ' . UnlockCode::TABLE_NAME . ' WHERE company_id = ?',
            [$companyId],
            [ParameterType::BINARY]
        );
    }
}
