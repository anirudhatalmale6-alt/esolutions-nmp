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
use SolidInvoice\CoreBundle\Entity\MarketplaceAd;
use SolidInvoice\CoreBundle\Repository\Traits\WithoutCompanyFilter;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends EntityRepository<MarketplaceAd>
 */
class MarketplaceAdRepository extends EntityRepository
{
    use WithoutCompanyFilter;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceAd::class);
    }

    /**
     * The adverts currently on the Marketplace home page, in slot order.
     *
     * Read with the company filter off on purpose: the home page is public, so
     * there is usually nobody signed in and therefore no company to scope to.
     * Left scoped, a paid advert would be invisible to exactly the buyers it was
     * bought to reach.
     *
     * @return list<MarketplaceAd>
     */
    public function findLive(): array
    {
        return $this->withoutCompanyFilter(fn (): array => $this->createQueryBuilder('a')
            ->addSelect('c')
            ->leftJoin('a.company', 'c')
            ->where('a.active = :active')
            ->andWhere('a.slot IS NOT NULL')
            ->andWhere('a.imagePath IS NOT NULL')
            ->setParameter('active', true)
            ->orderBy('a.slot', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Every advert, for the platform owner: placed ones first in slot order,
     * then everything waiting for a place, newest first.
     *
     * @return list<MarketplaceAd>
     */
    public function findAllForOwner(): array
    {
        return $this->withoutCompanyFilter(fn (): array => $this->createQueryBuilder('a')
            ->addSelect('c')
            ->leftJoin('a.company', 'c')
            ->orderBy('a.slot', 'ASC')
            ->addOrderBy('a.created', 'DESC')
            ->getQuery()
            ->getResult());
    }

    /**
     * One business's own adverts.
     *
     * @return list<MarketplaceAd>
     */
    public function findForCompany(Company $company): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.company = :company')
            ->setParameter('company', $company)
            ->orderBy('a.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * One advert by id, whoever it belongs to.
     *
     * Loading it through the entity manager instead would hand the owner a 404
     * on every advert but their own: find() runs under the company filter, and
     * the whole job of this desk is looking at other businesses' adverts.
     */
    public function findOneForOwner(string $id): ?MarketplaceAd
    {
        if (! Ulid::isValid($id)) {
            return null;
        }

        $ulid = Ulid::fromString($id);

        return $this->withoutCompanyFilter(fn (): ?MarketplaceAd => $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->setParameter('id', $ulid, UlidType::NAME)
            ->getQuery()
            ->getOneOrNullResult());
    }

    /**
     * Take a slot off whichever advert is holding it, so putting a new advert
     * into a place cannot end with two adverts in it and one of them drawn over
     * the other.
     */
    public function clearSlot(int $slot, ?MarketplaceAd $except = null): void
    {
        // Written as an UPDATE rather than loading the adverts and setting the
        // slot on each: filters are a SELECT-time thing, so this reaches every
        // business's advert regardless of who is signed in.
        $keep = $except instanceof MarketplaceAd ? $except->getId() : null;

        $dql = 'UPDATE ' . MarketplaceAd::class . ' a SET a.slot = NULL WHERE a.slot = :slot';

        if ($keep instanceof Ulid) {
            $dql .= ' AND a.id != :except';
        }

        $query = $this->getEntityManager()->createQuery($dql)
            ->setParameter('slot', $slot);

        if ($keep instanceof Ulid) {
            // The type has to be named. Left to guess it, Doctrine sends the
            // 26-character text form to a BINARY(16) column, matches nothing,
            // and the advert we meant to keep loses its slot too.
            $query->setParameter('except', $keep, UlidType::NAME);
        }

        $query->execute();
    }
}
