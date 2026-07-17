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
