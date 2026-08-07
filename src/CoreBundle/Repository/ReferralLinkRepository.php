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
use SolidInvoice\CoreBundle\Entity\ReferralLink;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use function strtoupper;
use function trim;

/**
 * @extends EntityRepository<ReferralLink>
 */
class ReferralLinkRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReferralLink::class);
    }

    /**
     * @return list<ReferralLink>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Look up a link by its code (case-insensitive). Returns null when the code is
     * unknown, so callers can reject an invalid join link.
     */
    public function findByCode(string $code): ?ReferralLink
    {
        return $this->findOneBy(['code' => strtoupper(trim($code))]);
    }

    /**
     * An active link for this code, or null. Used to gate registration: only a
     * known, still-active link may let a new business through.
     */
    public function findActiveByCode(string $code): ?ReferralLink
    {
        $link = $this->findByCode($code);

        return $link instanceof ReferralLink && $link->isActive() ? $link : null;
    }

    /**
     * Whether a code already exists (so the admin form can reject duplicates).
     */
    public function codeExists(string $code): bool
    {
        return $this->findByCode($code) instanceof ReferralLink;
    }

    // save() is inherited from EntityRepository (save(object $entity, bool $flush = true)):
    // overriding it here with a narrowed ReferralLink param broke PHP's signature
    // compatibility and took the whole app down, so we rely on the parent instead.

    /**
     * Remove a referral link. Named delete() (there is no delete() on the parent, so
     * this adds a method rather than overriding one); delegates to the parent remove().
     */
    public function delete(ReferralLink $link): void
    {
        $this->remove($link);
    }
}
