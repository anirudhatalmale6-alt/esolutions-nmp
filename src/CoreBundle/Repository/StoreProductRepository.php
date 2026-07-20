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
use SolidInvoice\CoreBundle\Entity\StoreProduct;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;

/**
 * @extends EntityRepository<StoreProduct>
 */
class StoreProductRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoreProduct::class);
    }

    /**
     * All products for the current company (CompanyFilter scopes automatically),
     * featured first then by curated position and name. Used by the admin list.
     *
     * @return list<StoreProduct>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.featured', 'DESC')
            ->addOrderBy('p.position', 'ASC')
            ->addOrderBy('p.make', 'ASC')
            ->addOrderBy('p.model', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All products for a specific company, ordered the same way. Scopes by
     * company EXPLICITLY so it is correct on the public (no-login) storefront
     * where the CompanyFilter is not active.
     *
     * @return list<StoreProduct>
     */
    public function findForCompany(Company $company): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.company = :company')
            ->setParameter('company', $company)
            ->orderBy('p.featured', 'DESC')
            ->addOrderBy('p.position', 'ASC')
            ->addOrderBy('p.make', 'ASC')
            ->addOrderBy('p.model', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Look up one product by its owner-assigned SKU within a company. Used by the
     * importer to update a row in place (and keep its uploaded photo) instead of
     * creating a duplicate on re-upload.
     */
    public function findOneBySkuForCompany(Company $company, string $sku): ?StoreProduct
    {
        return $this->createQueryBuilder('p')
            ->where('p.company = :company')
            ->andWhere('p.sku = :sku')
            ->setParameter('company', $company)
            ->setParameter('sku', $sku)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
