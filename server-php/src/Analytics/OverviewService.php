<?php

declare(strict_types=1);

namespace Aicountly\Api\Analytics;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Metrics\MetricCatalog;
use Aicountly\Api\Metrics\MetricResult;
use Aicountly\Api\Metrics\QueryService;
use Aicountly\Api\Support\Decimal;
use Aicountly\Api\Support\Format;
use Aicountly\Api\Support\Period;
use Aicountly\Api\Support\Sources;

/**
 * The executive overview: the figures, the trend, and what changed.
 *
 * THE OBSERVATIONS ARE RULES, NOT A MODEL. Every line on the "what needs
 * attention" panel is derived arithmetically from figures that are on the same
 * screen, and each one carries the evidence it was derived from. That is
 * deliberate: a dashboard that calls a language model on every load is slow,
 * costs money per refresh, and says something slightly different each time
 * somebody presses F5. The model is available on demand, from Ask Insights —
 * it is not in the render path.
 *
 * Observations state what is OBSERVED. "Collections fell 22% while sales rose
 * 4%" is a fact on the screen. "Collections fell because customers are
 * struggling" is a cause, and nothing here has the evidence for one.
 */
final class OverviewService
{
    /** The KPI row, in the order it is shown. */
    public const HEADLINE_METRICS = [
        'finance.net_revenue',
        'finance.collections',
        'finance.receivables',
        'finance.cash_and_bank',
    ];

    public const SECONDARY_METRICS = [
        'finance.overdue_receivables',
        'finance.payables',
        'finance.purchase_spend',
        'inventory.stock_value',
        'finance.net_profit',
        'finance.overdue_share',
    ];

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
        private readonly QueryService $query,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function build(Period $period, array $filters = []): array
    {
        $sources = new Sources();

        $metricIds = array_merge(self::HEADLINE_METRICS, self::SECONDARY_METRICS);
        $batch = $this->query->metrics($period, $metricIds, $filters);
        $sources->merge($batch['sources']);

        /** @var array<string, MetricResult> $results */
        $results = $batch['results'];

        $revenueTrend = $this->query->series($period->withGrain($this->trendGrain($period)), 'finance.net_revenue', $filters);
        $sources->merge($revenueTrend['sources']);

        $collectionsTrend = $this->query->series($period->withGrain($this->trendGrain($period)), 'finance.collections', $filters);
        $sources->merge($collectionsTrend['sources']);

        $receivablesAgeing = $this->query->breakdown($period, 'finance.receivables', 'ageing_bucket', $filters);
        $sources->merge($receivablesAgeing['sources']);

        $payablesAgeing = $this->query->breakdown($period, 'finance.payables', 'ageing_bucket', $filters);
        $sources->merge($payablesAgeing['sources']);

        $stockAgeing = $this->query->breakdown($period, 'inventory.ageing_value', 'ageing_bucket', $filters);
        $sources->merge($stockAgeing['sources']);

        return [
            'period'    => $period->toArray(),
            'headline'  => $this->serialise($results, self::HEADLINE_METRICS),
            'secondary' => $this->serialise($results, self::SECONDARY_METRICS),
            'trends'    => [
                'revenue'     => $revenueTrend['result']?->jsonSerialize(),
                'collections' => $collectionsTrend['result']?->jsonSerialize(),
            ],
            'ageing' => [
                'receivables' => $receivablesAgeing['result']?->jsonSerialize(),
                'payables'    => $payablesAgeing['result']?->jsonSerialize(),
                'stock'       => $stockAgeing['result']?->jsonSerialize(),
            ],
            'observations' => $this->observations($results, $period),
            'sources'      => $sources->toArray(),
            'unknown'      => $batch['unknown'],
        ];
    }

    /**
     * @param array<string, MetricResult> $results
     * @param list<string>                $ids
     * @return list<array<string, mixed>>
     */
    private function serialise(array $results, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $result = $results[$id] ?? null;
            if ($result !== null) {
                $out[] = $result->jsonSerialize();
            }
        }

        return $out;
    }

    /**
     * Evidence-backed observations.
     *
     * @param array<string, MetricResult> $results
     * @return list<array<string, mixed>>
     */
    private function observations(array $results, Period $period): array
    {
        $out = [];

        // 1. Sales and collections moving apart. The most useful single
        //    observation a business owner can be handed, and it is pure
        //    arithmetic on two figures already on the screen.
        $revenue = $this->change($results, 'finance.net_revenue');
        $collections = $this->change($results, 'finance.collections');

        if ($revenue !== null && $collections !== null) {
            $gap = Decimal::sub($revenue['change'], $collections['change']);
            $magnitude = Decimal::isNegative($gap) ? Decimal::negate($gap) : $gap;

            if (Decimal::cmp($magnitude, '10') >= 0) {
                $out[] = $this->observation(
                    Decimal::isNegative($gap) ? 'positive' : 'attention',
                    Decimal::isNegative($gap)
                        ? 'Collections are outpacing sales'
                        : 'Sales are running ahead of collections',
                    sprintf(
                        'Net sales %s %s and collections %s %s against %s. The gap is %s.',
                        $this->direction($revenue['change']),
                        Format::percent($this->abs($revenue['change']), 1),
                        $this->direction($collections['change']),
                        Format::percent($this->abs($collections['change']), 1),
                        $period->comparisonLabel(),
                        Format::percent($magnitude, 1) . ' of movement',
                    ),
                    [
                        $this->evidence($results, 'finance.net_revenue'),
                        $this->evidence($results, 'finance.collections'),
                    ],
                    Decimal::isNegative($gap)
                        ? 'Worth confirming that collections are not simply older invoices being settled.'
                        : 'Look at which customers billed in this period have not paid yet.',
                );
            }
        }

        // 2. Overdue concentration.
        $overdueShare = $results['finance.overdue_share'] ?? null;
        if ($overdueShare !== null && $overdueShare->isAvailable()) {
            $value = (string) $overdueShare->numericValue();
            if (Decimal::cmp($value, '25') > 0) {
                $out[] = $this->observation(
                    'attention',
                    'A quarter of what is owed is already overdue',
                    Format::percent($value, 1) . ' of receivables are past their due date.',
                    [
                        $this->evidence($results, 'finance.overdue_receivables'),
                        $this->evidence($results, 'finance.receivables'),
                    ],
                    'Open the ageing panel to see which bands the money sits in.',
                );
            }
        }

        // 3. Working capital against cash. A level comparison, both as at the
        //    same date — which is what makes them comparable at all.
        $stock = $results['inventory.stock_value'] ?? null;
        $cash = $results['finance.cash_and_bank'] ?? null;
        if ($stock !== null && $cash !== null && $stock->isAvailable() && $cash->isAvailable()) {
            $stockValue = (string) $stock->numericValue();
            $cashValue = (string) $cash->numericValue();
            if (!Decimal::isZero($cashValue) && Decimal::cmp($stockValue, Decimal::mul($cashValue, '3')) > 0) {
                $out[] = $this->observation(
                    'attention',
                    'Stock is large against the cash position',
                    'Inventory is worth ' . Format::money($stockValue) . ' while cash and bank stand at ' . Format::money($cashValue)
                        . ', both as at ' . Format::date($period->to) . '.',
                    [
                        $this->evidence($results, 'inventory.stock_value'),
                        $this->evidence($results, 'finance.cash_and_bank'),
                    ],
                    'The stock ageing panel shows which items the money is sitting in.',
                );
            }
        }

        // 4. Expenses growing faster than sales.
        $expense = $this->change($results, 'finance.expense');
        if ($revenue !== null && $expense !== null && Decimal::cmp($expense['change'], Decimal::add($revenue['change'], '10')) > 0) {
            $out[] = $this->observation(
                'attention',
                'Costs are rising faster than sales',
                sprintf(
                    'Expenses %s %s against %s, while net sales %s %s.',
                    $this->direction($expense['change']),
                    Format::percent($this->abs($expense['change']), 1),
                    $period->comparisonLabel(),
                    $this->direction($revenue['change']),
                    Format::percent($this->abs($revenue['change']), 1),
                ),
                [
                    $this->evidence($results, 'finance.expense'),
                    $this->evidence($results, 'finance.net_revenue'),
                ],
                'The expense breakdown shows which heads moved.',
            );
        }

        // 5. Sources that could not be reached. An overview missing a product
        //    is a different screen from one where everything is fine, and the
        //    reader should be told which they are looking at.
        $unavailable = [];
        foreach ($results as $result) {
            $payload = $result->jsonSerialize();
            if ($payload['status'] === MetricResult::UNAVAILABLE || $payload['status'] === MetricResult::DENIED) {
                $unavailable[] = $payload['label'];
            }
        }
        if ($unavailable !== []) {
            $out[] = $this->observation(
                'unavailable',
                'Some figures could not be read',
                count($unavailable) . ' of the figures on this page are unavailable: ' . implode(', ', array_slice($unavailable, 0, 6))
                    . (count($unavailable) > 6 ? ' and others' : '') . '. They are shown as unavailable rather than as zero.',
                [],
                'Data sources shows which product could not answer, and why.',
            );
        }

        if ($out === []) {
            $out[] = $this->observation(
                'positive',
                'Nothing stands out this period',
                'Sales, collections, receivables and cash all moved within their usual range against ' . $period->comparisonLabel() . '.',
                [],
                null,
            );
        }

        return $out;
    }

    /**
     * @param array<string, MetricResult> $results
     * @return array{change:string, value:?string}|null
     */
    private function change(array $results, string $metricId): ?array
    {
        $result = $results[$metricId] ?? null;
        if ($result === null || !$result->isAvailable()) {
            return null;
        }

        $payload = $result->jsonSerialize();
        $comparison = $payload['comparison'] ?? null;
        if (!is_array($comparison) || $comparison['change'] === null) {
            return null;
        }

        return ['change' => (string) $comparison['change'], 'value' => $payload['value']];
    }

    /**
     * @param array<string, MetricResult> $results
     * @return array<string, mixed>
     */
    private function evidence(array $results, string $metricId): array
    {
        $result = $results[$metricId] ?? null;
        $definition = MetricCatalog::get($metricId);

        if ($result === null) {
            return ['metric_id' => $metricId, 'label' => $definition?->label ?? $metricId, 'formatted' => 'Unavailable'];
        }

        $payload = $result->jsonSerialize();

        return [
            'metric_id'  => $metricId,
            'label'      => $payload['label'],
            'value'      => $payload['value'],
            'formatted'  => $payload['formatted'],
            'comparison' => $payload['comparison'],
            'definition' => $definition?->definition,
            'provenance' => $payload['provenance'],
            'drilldown'  => $payload['drilldown'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $evidence
     * @return array<string, mixed>
     */
    private function observation(string $tone, string $title, string $detail, array $evidence, ?string $nextStep): array
    {
        return [
            'tone'      => $tone,
            'title'     => $title,
            'detail'    => $detail,
            'evidence'  => array_values($evidence),
            'next_step' => $nextStep,
            // Said plainly, on every observation: this is arithmetic on the
            // figures shown, not a diagnosis of why they moved.
            'basis'     => 'Observed from the figures on this page. It describes what changed, not why.',
        ];
    }

    private function direction(string $change): string
    {
        if (Decimal::isZero($change)) {
            return 'held at';
        }

        return Decimal::isNegative($change) ? 'fell' : 'rose';
    }

    private function abs(string $value): string
    {
        return Decimal::isNegative($value) ? Decimal::negate($value) : $value;
    }

    private function trendGrain(Period $period): string
    {
        $days = $period->days();

        return match (true) {
            $days <= 31  => 'day',
            $days <= 120 => 'week',
            default      => 'month',
        };
    }
}
