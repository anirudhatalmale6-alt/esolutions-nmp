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
