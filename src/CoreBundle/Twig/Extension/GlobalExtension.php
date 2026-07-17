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

namespace SolidInvoice\CoreBundle\Twig\Extension;

use Carbon\Carbon;
use DateTime;
use Override;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Company\ResolvedHost;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Listener\HostRoutingListener;
use SolidInvoice\CoreBundle\Pdf\Generator;
use SolidInvoice\CoreBundle\SolidInvoiceCoreBundle;
use SolidInvoice\MoneyBundle\Calculator;
use SolidInvoice\SettingsBundle\SystemConfig;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;
use function implode;

class GlobalExtension extends AbstractExtension implements GlobalsInterface
{
    private const string DEFAULT_LOGO = 'png|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';

    public function __construct(
        private readonly Calculator $calculator,
        private readonly Generator $pdfGenerator,
        private readonly SystemConfig $systemConfig,
        private readonly RequestStack $requestStack,
        private readonly CompanySelector $companySelector,
        private readonly ?string $installed
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException|ServiceCircularReferenceException|ServiceNotFoundException
     */
    public function getGlobals(): array
    {
        return [
            'query' => $this->getQuery(),
            'app_version' => SolidInvoiceCoreBundle::VERSION,
            'app_name' => SolidInvoiceCoreBundle::APP_NAME,
        ];
    }

    /**
     * @return array<string, string>
     *
     * @throws ServiceCircularReferenceException|ServiceNotFoundException
     */
    protected function getQuery(): array
    {
        $request = $this->requestStack->getCurrentRequest();

        if (! $request instanceof Request) {
            return [];
        }

        $params = array_merge($request->query->all(), $request->attributes->all());

        foreach (array_keys($params) as $key) {
            if (str_starts_with($key, '_')) {
                unset($params[$key]);
            }
        }

        return $params;
    }

    /**
     * @return TwigFilter[]
     */
    #[Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('percentage', $this->calculator->calculatePercentage(...)),

            new TwigFilter('diff', $this->dateDiff(...)),

            new TwigFilter('md5', 'md5'),

            new TwigFilter('repeat', fn (string $string, int $times): string => str_repeat($string, $times)),
        ];
    }

    /**
     * @return TwigFunction[]
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('icon', $this->displayIcon(...), ['is_safe' => ['html']]),

            new TwigFunction('app_logo', $this->displayAppLogo(...), ['is_safe' => ['html'], 'needs_environment' => true]),

            new TwigFunction('company_name', function (): string {
                if ($this->companySelector->getCompany() instanceof Ulid) {
                    return $this->systemConfig->get('system/company/company_name') ?? SolidInvoiceCoreBundle::APP_NAME;
                }

                return SolidInvoiceCoreBundle::APP_NAME;
            }),

            new TwigFunction('company_id', $this->companySelector->getCompany(...)),

            new TwigFunction('can_print_pdf', $this->pdfGenerator->canPrintPdf(...)),

            new TwigFunction('is_custom_domain', $this->isCustomDomain(...)),
        ];
    }

    public function isCustomDomain(): bool
    {
        $request = $this->requestStack->getMainRequest();
        $resolved = $request?->attributes->get(HostRoutingListener::REQUEST_ATTR);

        return $resolved instanceof ResolvedHost && $resolved->isCustomDomain();
    }

    /**
     * @throws InvalidArgumentException|ServiceCircularReferenceException|ServiceNotFoundException|LoaderError|SyntaxError
     */
    public function displayAppLogo(Environment $env, string $width = 'auto', ?Company $company = null, bool $showDefault = false, bool $showOnlyAppIcon = false): string
    {
        $logo = $showDefault ? self::DEFAULT_LOGO : null;

        if ($this->installed && ! $showOnlyAppIcon) {
            $logo = $this->companySelector->getCompany() instanceof Ulid ? $this->systemConfig->get('system/company/logo', $company) : self::DEFAULT_LOGO;

            if (null === $logo) {
                $logo = $showDefault ? self::DEFAULT_LOGO : null;
            }
        }

        if (null === $logo) {
            return '';
        }

        [$type, $logo] = explode('|', $logo);

        return $env->createTemplate('<img src="data:image/{{ type }};base64,{{ logo }}" class="navbar-brand-image m-2" width="' . $width . '"/>')->render(['type' => $type, 'logo' => $logo]);
    }

    /**
     * @param list<string> $options
     */
    public function displayIcon(string $iconName, array $options = []): string
    {
        $class = sprintf('fa fa-%s', $iconName);

        if ([] !== $options) {
            $class .= ' ' . implode(' ', $options);
        }

        return sprintf('<i class="%s"></i>', $class);
    }

    /**
     * Returns a human-readable diff for dates.
     */
    public function dateDiff(DateTime $date): string
    {
        return Carbon::instance($date)->diffForHumans();
    }
}
