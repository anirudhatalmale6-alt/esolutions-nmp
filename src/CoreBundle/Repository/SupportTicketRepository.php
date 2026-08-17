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
use SolidInvoice\CoreBundle\Entity\SupportTicket;
use SolidInvoice\CoreBundle\Enum\SupportTicketStatus;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;

/**
 * @extends EntityRepository<SupportTicket>
 */
class SupportTicketRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupportTicket::class);
    }

    /**
     * Every ticket, for the platform owner. Newest activity first, not newest
     * ticket first - a three-week-old thread that somebody just replied to is
     * the thing that needs answering.
     *
     * @return list<SupportTicket>
     */
    public function findAllForOwner(): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('c')
            ->leftJoin('t.company', 'c')
            ->orderBy('t.awaitingOwner', 'DESC')
            ->addOrderBy('t.lastMessageAt', 'DESC')
            ->addOrderBy('t.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * One business's own tickets.
     *
     * @return list<SupportTicket>
     */
    public function findForCompany(Company $company): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.company = :company')
            ->setParameter('company', $company)
            ->orderBy('t.lastMessageAt', 'DESC')
            ->addOrderBy('t.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * How many tickets are sitting on the platform owner's side. Drives the count
     * beside Support in the sidebar - a support desk nobody can see the state of
     * is a support desk nobody answers.
     */
    public function countAwaitingOwner(): int
    {
        // Without the filter off this counts only tickets raised by whichever
        // company the owner happens to be inside - which is nearly always their
        // own, i.e. zero, while the queue quietly fills up. A ticket has a
        // `company` association, so the filter applies to it like anything else.
        return $this->withoutCompanyFilter(fn (): int => (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.awaitingOwner = :awaiting')
            ->andWhere('t.status != :closed')
            ->setParameter('awaiting', true)
            ->setParameter('closed', SupportTicketStatus::Closed->value)
            ->getQuery()
            ->getSingleScalarResult());
    }

    /**
     * Every ticket, for the platform owner, with the company filter off.
     *
     * @return list<SupportTicket>
     */
    public function findAllForOwnerUnscoped(): array
    {
        return $this->withoutCompanyFilter(fn (): array => $this->findAllForOwner());
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withoutCompanyFilter(callable $callback): mixed
    {
        $filters = $this->getEntityManager()->getFilters();
        $wasEnabled = $filters->isEnabled('company');

        if ($wasEnabled) {
            $filters->disable('company');
        }

        try {
            return $callback();
        } finally {
            if ($wasEnabled) {
                $filters->enable('company');
            }
        }
    }

    /**
     * How many of this business's tickets have an answer they have not opened.
     */
    public function countUnreadForCompany(Company $company): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.company = :company')
            ->andWhere('t.unreadByMember = :unread')
            ->setParameter('company', $company)
            ->setParameter('unread', true)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
