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
use SolidInvoice\CoreBundle\Entity\StockGrade;
use SolidInvoice\CoreBundle\Entity\StockModel;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;

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
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The lightweight list behind the model picker on invoice / purchase lines:
     * id, name, quantity in hand, and the grade breakdown.
     *
     * The grades are here because a line sells a grade, not an item - "S22" is
     * not something anybody sells, "S22 Grade A" is. The picker offers one entry
     * per grade so the choice is made once, while typing, instead of through a
     * second dropdown afterwards.
     *
     * Deliberately NOT the full entities - this is fetched on every billing page
     * and a vendor can hold hundreds of models, so it stays flat arrays and two
     * queries. Company scoping comes from the CompanyFilter.
     *
     * @return list<array{id: string, name: string, qty: int, grades: list<array{id: string, grade: string, qty: int}>}>
     */
    public function pickerList(): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.id', 'm.name', 'm.quantity')
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $grades = $this->gradesByModel();
        $list = [];

        foreach ($rows as $row) {
            $id = (string) $row['id'];

            $list[] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'qty' => (int) $row['quantity'],
                'grades' => $grades[$id] ?? [],
            ];
        }

        return $list;
    }

    /**
     * Every grade of this company's stock, keyed by the item it belongs to.
     *
     * @return array<string, list<array{id: string, grade: string, qty: int}>>
     */
    private function gradesByModel(): array
    {
        $rows = $this->getEntityManager()
            ->createQuery(
                'SELECT g.id AS id, g.grade AS grade, g.quantity AS quantity, IDENTITY(g.stockModel) AS model_id
                 FROM ' . StockGrade::class . ' g
                 JOIN g.stockModel m
                 ORDER BY g.grade ASC'
            )
            ->getArrayResult();

        $grades = [];

        foreach ($rows as $row) {
            $modelId = StockMovementRepository::normaliseIdentity($row['model_id']);

            if ($modelId === null) {
                continue;
            }

            $grades[$modelId][] = [
                'id' => (string) $row['id'],
                'grade' => (string) $row['grade'],
                'qty' => (int) $row['quantity'],
            ];
        }

        return $grades;
    }

    /*
     * deleteForCompany() used to live here: the Tally import called it to wipe
     * the company's stock before rebuilding it from the sheet.
     *
     * It is gone deliberately, and must not come back. Deleting a stock model
     * takes two things with it that are not obvious from the delete itself: the
     * invoice and purchase lines pointing at it lose the link (the foreign key
     * is ON DELETE SET NULL), and its entire movement history goes too (ON
     * DELETE CASCADE) - so the audit trail behind every quantity disappears
     * without a trace. The importer now matches items by name and adjusts what
     * is already there instead; see StockImporter.
     */
}
