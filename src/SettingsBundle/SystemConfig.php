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

namespace SolidInvoice\SettingsBundle;

use Money\Currency;
use RuntimeException;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\SettingsBundle\Repository\SettingsRepository;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * @see \SolidInvoice\SettingsBundle\Tests\SystemConfigTest
 */
class SystemConfig
{
    final public const string CURRENCY_CONFIG_PATH = 'system/company/currency';

    /**
     * @var array<string, string>
     */
    private static array $settings = [];

    public function __construct(
        private readonly ?string $installed,
        private readonly SettingsRepository $repository,
    ) {
    }

    public function get(string $key, ?Company $company = null): ?string
    {
        if (null === $this->installed || '' === $this->installed) {
            return null;
        }

        if ($company instanceof Company) {
            return $this->getForCompany($company, $key);
        }

        return $this->repository->getSetting($key)?->getValue();
    }

    /**
     * A setting that belongs to the platform, not to one business: the mail
     * transport and the address it sends from. Read without depending on which
     * company - if any - the request is working in.
     *
     * @see SettingsRepository::platformValue() for why the ordinary read cannot
     *      be trusted for these two, and what "the platform's" value means.
     */
    public function getPlatformWide(string $key): ?string
    {
        if (null === $this->installed || '' === $this->installed) {
            return null;
        }

        return $this->repository->platformValue($key);
    }

    /**
     * A named company's setting, read without depending on - or disturbing - the
     * company the request happens to be working in. A document uses this so it
     * always shows the details of the business that issued it.
     */
    public function getForCompany(Company $company, string $key): ?string
    {
        if (null === $this->installed || '' === $this->installed) {
            return null;
        }

        $companyId = $company->getId();

        if (! $companyId instanceof Ulid) {
            return null;
        }

        return $this->repository->valueForCompany($companyId, $key);
    }

    /**
     * @throws Throwable
     */
    public function set(string $path, mixed $value): void
    {
        $this->repository->store([$path => $value]);
        self::$settings = [];
    }

    /**
     * @return array<string, string>
     */
    public function getAll(): array
    {
        $this->load();

        return self::$settings;
    }

    private function load(): void
    {
        if ([] === self::$settings) {
            try {
                $settings = $this->repository
                    ->createQueryBuilder('c')
                    ->select('c.key', 'c.value')
                    ->orderBy('c.key')
                    ->getQuery()
                    ->getArrayResult();
            } catch (Throwable) {
                return;
            }

            self::$settings = array_combine(array_column($settings, 'key'), array_column($settings, 'value'));
        }
    }

    public function remove(string $key): void
    {
        $this->repository->delete($key);
        self::$settings = [];
    }

    public function getCurrency(): Currency
    {
        $currency = $this->get(self::CURRENCY_CONFIG_PATH);

        if (null === $currency || '' === $currency) {
            throw new RuntimeException('No currency set');
        }

        return new Currency($currency);
    }
}
