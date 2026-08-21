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

namespace SolidInvoice\UserBundle\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\WhatsApp\WhatsAppSender;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function is_string;
use function sprintf;

/**
 * @see \SolidInvoice\UserBundle\Tests\Repository\UserRepositoryTest
 *
 * @extends \SolidWorx\Platform\PlatformBundle\Repository\UserRepository<User>
 */
class UserRepository extends \SolidWorx\Platform\PlatformBundle\Repository\UserRepository implements UserRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function getUserCount(): int
    {
        $qb = $this->createQueryBuilder('u');

        $qb->select('COUNT(u.id)');

        try {
            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (NoResultException|NonUniqueResultException|Exception) {
            return 0;
        }
    }

    /**
     * Counts users associated with the given company. Used by the `team_seats`
     * quota gate. Scoped explicitly via the user-companies join (not the global
     * `CompanyFilter`, since `User` participates as the *inverse* side of the
     * Many-to-Many on `Company::users`).
     */
    public function getUserCountForCompany(Company $company): int
    {
        $qb = $this->createQueryBuilder('u');

        $qb->select('COUNT(u.id)')
            ->innerJoin('u.companies', 'c')
            ->where('c.id = :companyId')
            ->setParameter('companyId', $company->getId(), UlidType::NAME);

        try {
            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (NoResultException|NonUniqueResultException|Exception) {
            return 0;
        }
    }

    public function getGridQuery(): QueryBuilder
    {
        $qb = $this->createQueryBuilder('u');

        $qb->select('u.id', 'u.email', 'u.mobile', 'u.enabled', 'u.created', 'u.lastLogin')
            ->groupBy('u.id');

        return $qb;
    }

    /**
     * Is this WhatsApp number already on an account?
     *
     * Compared as chat ids rather than as text. The same number reaches the
     * same phone written as +971 50 123 4567, 00971501234567 or
     * 971501234567, so an exact match on the column would wave through the
     * duplicate sign-ups this is meant to stop. WhatsAppSender::chatId() is
     * what the gateway addresses the message to, which makes it the only
     * definition of "the same number" that means anything.
     *
     * Read as a scalar list of the numbers on file, not as hydrated users:
     * that is one small query and no entity objects, and the accounts on a
     * portal like this number in the hundreds.
     */
    public function isWhatsAppNumberTaken(string $number, ?Ulid $ignoreUserId = null): bool
    {
        if (WhatsAppSender::chatId($number) === null) {
            // Not a number anything could be sent to, so it cannot be a
            // duplicate of a real one. WhatsAppNumber reports the format.
            return false;
        }

        $qb = $this->createQueryBuilder('u')
            ->select('u.mobile')
            ->where('u.mobile IS NOT NULL')
            ->andWhere("u.mobile != ''");

        if ($ignoreUserId instanceof Ulid) {
            $qb->andWhere('u.id != :ignore')
                ->setParameter('ignore', $ignoreUserId, UlidType::NAME);
        }

        foreach ($qb->getQuery()->getSingleColumnResult() as $existing) {
            if (is_string($existing) && WhatsAppSender::isSameNumber($existing, $number)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Accounts that belong to no business at all.
     *
     * These are leftovers. Deleting a company used to remove the company first
     * and then the accounts that existed only for it, in two separate commits -
     * so when the second half failed (see Version30000_48) the company went and
     * the account stayed, holding on to its e-mail address and WhatsApp number
     * and refusing to let either be used again. Nothing in the console showed
     * them, because every list there is drawn per company and these are in none.
     *
     * Must be called with the company filter suspended: that filter narrows User
     * to the members of the CURRENT company, which by definition excludes every
     * row this asks for.
     *
     * @return list<User>
     */
    public function findWithoutCompany(): array
    {
        return $this->createQueryBuilder('u')
            ->where('SIZE(u.companies) = 0')
            ->orderBy('u.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getRecentlyJoinedCount(int $days = 30): int
    {
        $qb = $this->createQueryBuilder('u');
        $date = new DateTimeImmutable(sprintf('-%d days', $days));

        $qb->select('COUNT(u.id)')
            ->where('u.created >= :date')
            ->setParameter('date', $date);

        try {
            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (NoResultException|NonUniqueResultException|Exception) {
            return 0;
        }
    }
}
