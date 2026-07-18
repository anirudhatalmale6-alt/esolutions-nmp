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
