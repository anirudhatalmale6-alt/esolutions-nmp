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

namespace SolidInvoice\SettingsBundle\Repository;

use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\SettingsBundle\Entity\Setting;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use Throwable;
use function assert;
use function is_array;

/**
 * @extends EntityRepository<Setting>
 */
class SettingsRepository extends EntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly CompanySelector $companySelector,
    ) {
        parent::__construct($registry, Setting::class);
    }

    /**
     * @param array<string, string> $settings
     *
     * @throws InvalidArgumentException|Throwable
     */
    public function store(array $settings): void
    {
        $settings = $this->flatten($settings);
        $entityManager = $this->getEntityManager();

        // The "company" Doctrine filter only rewrites SELECTs - Doctrine does NOT
        // apply filters to a DQL UPDATE. Without an explicit company condition the
        // UPDATE below matches the row for EVERY company that shares the setting
        // key, so one business saving its settings would overwrite every other
        // business's company name, contact details, logo and mail credentials.
        // Scope it by hand, and refuse to write at all with no company context
        // rather than fall back to updating everyone.
        $companyId = $this->companySelector->getCompany();

        if (! $companyId instanceof Ulid) {
            throw new LogicException('Settings can only be saved while working inside a company.');
        }

        try {
            $entityManager->wrapInTransaction(function () use ($settings, $companyId): void {
                foreach ($settings as $key => $value) {
                    if ('system/domain/custom_domain' === $key) {
                        $companyRepository = $this->getEntityManager()->getRepository(Company::class);
                        assert($companyRepository instanceof CompanyRepository);
                        $value = $companyRepository->updateCustomDomain(empty($value) ? null : $value);
                    }

                    $this->createQueryBuilder('s')
                        ->update()
                        ->set('s.value', ':val')
                        ->where('s.key = :key')
                        ->andWhere('s.company = :company')
                        ->setParameter('key', $key)
                        ->setParameter('company', $companyId, UlidType::NAME)
                        ->setParameter('val', empty($value) ? null : $value)
                        ->getQuery()
                        ->execute();

                    if ('system/company/company_name' === $key) {
                        $this->getEntityManager()->getRepository(Company::class)->updateCompanyName($value);
                    }
                }
            });
        } finally {
            // Detach the entities, to not keep previous setting values
            $unitOfWork = $entityManager->getUnitOfWork();
            $entities = $unitOfWork->getIdentityMap()[Setting::class] ?? [];

            foreach ($entities as $entity) {
                $entityManager->detach($entity);
            }
        }
    }

    public function delete(string $key): void
    {
        $this->_em->remove($this->findOneBy(['key' => $key]));
        $this->_em->flush();
    }

    /**
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    private function flatten(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result = [...$result, ...$this->flatten($value, $prefix . $key . '/')];
            } else {
                $result[$prefix . $key] = $value;
            }
        }

        return $result;
    }

    /**
     * The setting for the company the request is currently working in. The
     * "company" Doctrine filter does the scoping.
     *
     * To read a NAMED company's setting, use {@see self::valueForCompany()}.
     * This used to take a company and switch the whole request over to it (reset
     * the filter, then switch to it in a finally), so a template asking for one
     * other company's setting silently re-tenanted everything rendered after it.
     */
    public function getSetting(string $key, ?Company $company = null): ?Setting
    {
        if ($company instanceof Company) {
            return $this->findOneBy(['key' => $key, 'company' => $company]);
        }

        return $this->findOneBy(['key' => $key]);
    }

    /**
     * The raw value of a setting belonging to a named company, read without
     * touching - or depending on - the company the request is currently working
     * in. This is what a document (invoice, quote) uses so it always prints the
     * details of the business that issued it, whoever happens to be looking at
     * it and whichever company they are signed into.
     */
    /**
     * A setting that belongs to the platform rather than to one business - the
     * mail transport and the address it sends from.
     *
     * Every company gets its own row for every setting the moment it is created
     * (DefaultData::createAppConfig), and all of them start empty. So a plain
     * read of `email/sending_options/provider` returns:
     *
     *   - inside a company: that company's row. Empty for every member, because
     *     only the platform owner ever fills the mail settings in.
     *   - with NO company selected - which is exactly the case while somebody is
     *     registering - an arbitrary one of those rows, because the "company"
     *     filter has nothing to scope by. Usually an empty one, and it gets more
     *     likely with every business that joins.
     *
     * Either way an empty result sends Symfony back to SOLIDINVOICE_MAILER_DSN,
     * which defaults to null://null: the mail is accepted and thrown away
     * without an error. That is a silent failure, so it must not depend on who
     * happens to be signed in.
     *
     * Order: this company's own value if it has one, otherwise the oldest
     * company that has actually set one. Ulids sort by creation time, so that is
     * the business the platform was installed for - deterministic, and the same
     * answer on every request.
     */
    public function platformValue(string $key): ?string
    {
        $companyId = $this->companySelector->getCompany();

        if ($companyId instanceof Ulid) {
            $own = $this->deliberateValue($companyId, $key);

            if ($own !== null) {
                return $own;
            }
        }

        try {
            $value = $this->getEntityManager()
                ->getConnection()
                ->fetchOne(
                    'SELECT setting_value FROM ' . Setting::TABLE_NAME
                    . " WHERE setting_key = ? AND setting_value IS NOT NULL AND setting_value <> ''"
                    . ' AND (default_value IS NULL OR setting_value <> default_value)'
                    . ' ORDER BY company_id ASC LIMIT 1',
                    [$key],
                    [ParameterType::STRING],
                );
        } catch (Throwable) {
            return null;
        }

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * A company's value for a setting, but only if somebody actually chose it.
     *
     * Every company is seeded with every setting AND the shipped default in the
     * same row (DefaultData::createAppConfig), and `email/from_address` ships as
     * no-reply@solidinvoice.co. A row still holding its default is not a choice,
     * it is a company that has never opened the page - and treating it as one
     * would send every member's mail From a domain the portal does not own,
     * which SPF then bins.
     */
    private function deliberateValue(Ulid $companyId, string $key): ?string
    {
        try {
            $row = $this->getEntityManager()
                ->getConnection()
                ->fetchAssociative(
                    'SELECT setting_value, default_value FROM ' . Setting::TABLE_NAME
                    . ' WHERE company_id = ? AND setting_key = ?',
                    [$companyId->toBinary(), $key],
                    [ParameterType::BINARY, ParameterType::STRING],
                );
        } catch (Throwable) {
            return null;
        }

        if (! is_array($row)) {
            return null;
        }

        $value = $row['setting_value'];

        if ($value === null || $value === '' || $value === $row['default_value']) {
            return null;
        }

        return (string) $value;
    }

    /**
     * The name of the business whose row {@see self::platformValue()} would use
     * for this key, so the Email Check page can say whose settings are actually
     * in force rather than only that there are some.
     */
    public function platformValueOwner(string $key): ?string
    {
        $companyId = $this->companySelector->getCompany();

        if ($companyId instanceof Ulid && $this->deliberateValue($companyId, $key) !== null) {
            return $this->companyName($companyId);
        }

        try {
            $name = $this->getEntityManager()
                ->getConnection()
                ->fetchOne(
                    'SELECT c.name FROM ' . Setting::TABLE_NAME . ' s'
                    . ' INNER JOIN ' . Company::TABLE_NAME . ' c ON c.id = s.company_id'
                    . " WHERE s.setting_key = ? AND s.setting_value IS NOT NULL AND s.setting_value <> ''"
                    . ' AND (s.default_value IS NULL OR s.setting_value <> s.default_value)'
                    . ' ORDER BY s.company_id ASC LIMIT 1',
                    [$key],
                    [ParameterType::STRING],
                );
        } catch (Throwable) {
            return null;
        }

        return $name === false || $name === null ? null : (string) $name;
    }

    private function companyName(Ulid $companyId): ?string
    {
        try {
            $name = $this->getEntityManager()
                ->getConnection()
                ->fetchOne(
                    'SELECT name FROM ' . Company::TABLE_NAME . ' WHERE id = ?',
                    [$companyId->toBinary()],
                    [ParameterType::BINARY],
                );
        } catch (Throwable) {
            return null;
        }

        return $name === false || $name === null ? null : (string) $name;
    }

    public function valueForCompany(Ulid $companyId, string $key): ?string
    {
        try {
            $value = $this->getEntityManager()
                ->getConnection()
                ->fetchOne(
                    'SELECT setting_value FROM ' . Setting::TABLE_NAME . ' WHERE company_id = ? AND setting_key = ?',
                    [$companyId->toBinary(), $key],
                    [ParameterType::BINARY, ParameterType::STRING],
                );
        } catch (Throwable) {
            return null;
        }

        return $value === false || $value === null ? null : (string) $value;
    }
}
