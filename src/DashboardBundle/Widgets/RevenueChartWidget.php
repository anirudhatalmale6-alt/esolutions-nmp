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

namespace SolidInvoice\DashboardBundle\Widgets;

use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateMalformedStringException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use SolidInvoice\PaymentBundle\Entity\Payment;
use SolidInvoice\PaymentBundle\Repository\PaymentRepository;
use SolidInvoice\SettingsBundle\SystemConfig;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * @see \SolidInvoice\DashboardBundle\Tests\Widgets\RevenueChartWidgetTest
 */
final readonly class RevenueChartWidget implements WidgetInterface
{
    /**
     * How many buckets to show, per period. Kept modest so the line stays
     * readable: a year of weeks, a year of months, two years of quarters, and
     * five years.
     */
    private const PERIODS = [
        'weekly' => 12,
        'monthly' => 12,
        'quarterly' => 8,
        'annual' => 5,
    ];

    private ObjectManager $manager;

    public function __construct(
        ManagerRegistry $registry,
        private ChartBuilderInterface $chartBuilder,
        private SystemConfig $systemConfig,
        private RequestStack $requestStack,
    ) {
        $this->manager = $registry->getManager();
    }

    /**
     * @return array<string, mixed>
     * @throws DateMalformedStringException
     */
    public function getData(): array
    {
        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $this->manager->getRepository(Payment::class);

        $period = $this->resolvePeriod();
        $count = self::PERIODS[$period];
        $now = CarbonImmutable::now();

        // Build the ordered list of buckets (oldest -> newest) for this period,
        // each with a stable key and a human label for the axis.
        $buckets = [];
        for ($i = $count - 1; $i >= 0; --$i) {
            $start = $this->periodStart($period, $now, $i);
            $buckets[] = $this->describe($period, $start);
        }

        $since = $this->periodStart($period, $now, $count - 1);

        // Fetch captured payments once, then bucket them in PHP by the same key.
        $revenueData = [];
        foreach ($paymentRepository->getCapturedRevenueSince($since) as $row) {
            $key = $this->describe($period, CarbonImmutable::instance($row['created']))['key'];
            $currency = $row['currencyCode'];

            $revenueData[$key][$currency] = ($revenueData[$key][$currency] ?? BigInteger::zero())
                ->plus(BigNumber::of($row['totalAmount']));
        }

        $labels = array_column($buckets, 'label');

        // Get all currencies from the data
        $currencies = [];
        foreach ($revenueData as $monthData) {
            foreach (array_keys($monthData) as $currency) {
                if (! in_array($currency, $currencies, true)) {
                    $currencies[] = $currency;
                }
            }
        }

        // If no data, default to a single currency placeholder
        if ([] === $currencies) {
            $currencies = [$this->systemConfig->getCurrency()->getCode()];
        }

        // Build datasets for each currency
        $datasets = [];
        $colors = [
            ['border' => 'rgb(46, 150, 58)', 'background' => 'rgba(46, 150, 58, 0.1)'],
            ['border' => 'rgb(59, 130, 246)', 'background' => 'rgba(59, 130, 246, 0.1)'],
            ['border' => 'rgb(245, 158, 11)', 'background' => 'rgba(245, 158, 11, 0.1)'],
            ['border' => 'rgb(139, 92, 246)', 'background' => 'rgba(139, 92, 246, 0.1)'],
        ];

        foreach ($currencies as $index => $currency) {
            $data = [];
            foreach ($buckets as $bucket) {
                $bucketKey = $bucket['key'];
                $data[] = isset($revenueData[$bucketKey][$currency])
                    ? $revenueData[$bucketKey][$currency]->dividedBy(BigNumber::of(100), RoundingMode::HalfEven)->toFloat() // Convert cents to currency units
                    : 0;
            }

            $colorIndex = $index % count($colors);
            $datasets[] = [
                'label' => $currency,
                'data' => $data,
                'borderColor' => $colors[$colorIndex]['border'],
                'backgroundColor' => $colors[$colorIndex]['background'],
                'fill' => true,
                'tension' => 0.4,
                'pointRadius' => 4,
                'pointHoverRadius' => 6,
            ];
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $labels,
            'datasets' => $datasets,
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'interaction' => [
                'intersect' => false,
                'mode' => 'index',
            ],
            'plugins' => [
                'legend' => [
                    'display' => count($currencies) > 1,
                    'position' => 'top',
                ],
                'tooltip' => [
                    'backgroundColor' => 'rgba(30, 41, 59, 0.9)',
                    'titleColor' => '#fff',
                    'bodyColor' => '#fff',
                    'borderColor' => 'rgba(255, 255, 255, 0.1)',
                    'borderWidth' => 1,
                    'padding' => 12,
                    'cornerRadius' => 8,
                ],
            ],
            'scales' => [
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                    'ticks' => [
                        'color' => '#64748b',
                    ],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'grid' => [
                        'color' => 'rgba(0, 0, 0, 0.05)',
                    ],
                    'ticks' => [
                        'color' => '#64748b',
                    ],
                ],
            ],
        ]);

        return [
            'chart' => $chart,
            'hasData' => ! empty($revenueData),
            'period' => $period,
            'periods' => array_keys(self::PERIODS),
        ];
    }

    /**
     * The chosen period from ?revenue_period=..., validated against the allowed
     * list, defaulting to monthly.
     */
    private function resolvePeriod(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $requested = $request !== null ? (string) $request->query->get('revenue_period', '') : '';

        return isset(self::PERIODS[$requested]) ? $requested : 'monthly';
    }

    /**
     * The start date of the bucket $offset periods before "now" (offset 0 = the
     * current, still-open period).
     */
    private function periodStart(string $period, CarbonImmutable $now, int $offset): CarbonImmutable
    {
        return match ($period) {
            'weekly' => $now->startOfWeek()->subWeeks($offset),
            'quarterly' => $now->startOfQuarter()->subQuarters($offset),
            'annual' => $now->startOfYear()->subYears($offset),
            default => $now->startOfMonth()->subMonths($offset),
        };
    }

    /**
     * Turn a date into its bucket: a stable key (so the same period always maps
     * to the same slot) and a short human label for the chart axis.
     *
     * @return array{key: string, label: string}
     */
    private function describe(string $period, CarbonImmutable $date): array
    {
        return match ($period) {
            'weekly' => [
                'key' => $date->format('o-\WW'),
                'label' => $date->startOfWeek()->format('d M'),
            ],
            'quarterly' => [
                'key' => $date->format('Y') . '-Q' . $date->quarter,
                'label' => 'Q' . $date->quarter . ' ' . $date->format('Y'),
            ],
            'annual' => [
                'key' => $date->format('Y'),
                'label' => $date->format('Y'),
            ],
            default => [
                'key' => $date->format('Y-m'),
                'label' => $date->format('M Y'),
            ],
        };
    }

    public function getTemplate(): string
    {
        return '@SolidInvoiceDashboard/Widget/revenue_chart.html.twig';
    }
}
