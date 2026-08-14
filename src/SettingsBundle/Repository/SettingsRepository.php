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
