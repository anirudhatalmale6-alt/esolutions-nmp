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
use SolidInvoice\CoreBundle\Entity\StockGrade;
use SolidInvoice\CoreBundle\Entity\StockModel;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;

/**
 * @extends EntityRepository<StockModel>
 */
class StockModelRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockModel::class);
    }

    /**
     * All stock models for the current company, grades eager-loaded, name-sorted.
     * The company scoping is applied automatically by the CompanyFilter.
     *
     * @return list<StockModel>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.grades', 'g')
            ->addSelect('g')
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All stock models for a specific company, grades eager-loaded, name-sorted.
     * Scopes by company explicitly so it is correct even on public (no-login)
     * requests where the CompanyFilter is not active.
     *
     * @return list<StockModel>
     */
    public function findForCompany(Company $company): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.grades', 'g')
            ->addSelect('g')
            ->where('m.company = :company')
            ->setParameter('company', $company)
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Remove every stock model, and its grades, belonging to the given company.
     * Used before a re-import so uploading a fresh list REPLACES the previous
     * one instead of stacking duplicates on top of it.
     *
     * The grades are deleted first (explicitly) so the model delete can never
     * trip a foreign-key constraint - this does not rely on the database FK
     * having been created with ON DELETE CASCADE, which is not guaranteed on a
     * schema that was built up with doctrine:schema:update.
     *
     * @return int the number of stock models that were removed
     */
    public function deleteForCompany(Company $company): int
    {
        $companyId = $company->getId()?->toBinary();

        if ($companyId === null) {
            return 0;
        }

        // Delete straight through the DBAL connection on the real tables, by the
        // binary company_id. This deliberately bypasses the ORM/DQL bulk-delete
        // path (which was leaving the previous import in place) and the Doctrine
        // company SQL-filter, so a re-upload reliably wipes the old stock first.
        $connection = $this->getEntityManager()->getConnection();

        // 1. Child grades first (models of this company).
        $connection->executeStatement(
            'DELETE FROM ' . StockGrade::TABLE_NAME
            . ' WHERE stock_model_id IN (SELECT id FROM ' . StockModel::TABLE_NAME . ' WHERE company_id = ?)',
            [$companyId],
            [ParameterType::BINARY]
        );

        // 2. The models themselves - return how many rows were cleared.
        return (int) $connection->executeStatement(
            'DELETE FROM ' . StockModel::TABLE_NAME . ' WHERE company_id = ?',
            [$companyId],
            [ParameterType::BINARY]
        );
    }
}
