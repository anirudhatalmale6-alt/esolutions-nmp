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

namespace SolidInvoice\SettingsBundle\Twig\Extension;

use const JSON_THROW_ON_ERROR;
use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use SolidInvoice\ClientBundle\Entity\Address;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\SettingsBundle\SystemConfig;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use function json_decode;

/**
 * @see \SolidInvoice\SettingsBundle\Tests\Twig\Extension\SettingsExtensionTest
 */
class SettingsExtension extends AbstractExtension
{
    public function __construct(
        private readonly SystemConfig $config
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('setting', fn (string $setting, $default = null, $decode = false) => $this->getSetting($setting, $default, $decode)),
            // Same as setting(), but for a named company rather than whichever
            // company the request is in. Invoices and quotes use this so the
            // "From" block is always the business that issued the document.
            new TwigFunction('company_setting', fn (?Company $company, string $setting, $default = null, $decode = false) => $this->getCompanySetting($company, $setting, $default, $decode)),
            new TwigFunction('address', fn (array $address) => $this->renderAddress($address)),
        ];
    }

    /**
     * @param array<string, string|null> $address
     */
    public function renderAddress(array $address): string
    {
        return (string) Address::fromArray($address);
    }

    /**
     * @param mixed|null $default
     *
     * @return mixed|null
     *
     * @throws JsonException
     */
    public function getSetting(string $key, $default = null, bool $decode = false)
    {
        try {
            $setting = $this->config->get($key);
        } catch (Exception) {
            return $default;
        }

        return $this->present($setting, $default, $decode);
    }

    /**
     * @param mixed|null $default
     *
     * @return mixed|null
     *
     * @throws JsonException
     */
    public function getCompanySetting(?Company $company, string $key, $default = null, bool $decode = false)
    {
        if (! $company instanceof Company) {
            return $this->getSetting($key, $default, $decode);
        }

        try {
            $setting = $this->config->getForCompany($company, $key);
        } catch (Exception) {
            return $default;
        }

        return $this->present($setting, $default, $decode);
    }

    /**
     * @param mixed|null $default
     *
     * @return mixed|null
     *
     * @throws JsonException
     */
    private function present(?string $setting, $default, bool $decode)
    {
        if (null === $setting) {
            return $default;
        }

        if ($decode && $setting) {
            return json_decode($setting, true, 512, JSON_THROW_ON_ERROR);
        }

        return $setting;
    }
}
