<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Analytics\ForecastService;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Metrics\CustomMetrics;
use Aicountly\Api\Metrics\MetricCatalog;
use Aicountly\Api\Metrics\MetricResult;
use Aicountly\Api\Metrics\QueryService;
use Aicountly\Api\Support\Period;
use Aicountly\Api\Support\Sources;

/**
 * Turning a saved widget into the figures it shows.
 *
 * THE VIEWER'S PERMISSIONS ARE APPLIED HERE, EVERY TIME. This service is handed
 * the caller's own QueryService, which holds the caller's own session key, and
 * every fetch it makes goes to the owning product as that person. There is no
 * path by which a dashboard's OWNER's data could be returned to somebody it was
 * shared with: the owner's session is not in this process, and nothing about
 * their result was stored.
 *
 * A widget whose viewer cannot see the underlying data renders as a widget
 * saying so — with the layout intact, which is what makes a shared dashboard
 * useful to a restricted colleague rather than a wall of errors.
 */
final class WidgetQueryService
{
    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
        private readonly QueryService $query,
    ) {
    }

    /**
     * Answer every widget on a board.
     *
     * Batched by shape: all the KPI cards on a board are one fetch, because the
     * adapters batch by metric. Twenty cards costs the same round trips as one.
     *
     * @param list<array<string, mixed>> $widgets
     * @param array<string, mixed>       $dashboardSettings
     * @return array{widgets: array<string, array<string, mixed>>, sources: list<array<string, mixed>>, period: array<string, mixed>}
     */
    public function renderAll(array $widgets, array $dashboardSettings, ?Period $override = null): array
    {
        $period = $override ?? Period::fromConfig($dashboardSettings, (string) ($dashboardSettings['grain'] ?? 'month'));
        $sources = new Sources();

        // One pass to collect every metric the simple widgets need, so they are
        // fetched together rather than one call per card.
        $flat = [];
        foreach ($widgets as $widget) {
            if (!$this->isFlat((string) ($widget['widget_type'] ?? ''))) {
                continue;
            }
            $metricId = $widget['config']['metric_id'] ?? null;
            if (is_string($metricId)) {
                $flat[] = $metricId;
            }
            foreach (($widget['config']['extra_metrics'] ?? []) as $extra) {
                if (is_string($extra)) {
                    $flat[] = $extra;
                }
            }
        }

        $batch = $flat === []
            ? ['results' => [], 'sources' => new Sources(), 'unknown' => []]
            : $this->query->metrics($period, array_values(array_unique($flat)), $this->filters($dashboardSettings));

        $sources->merge($batch['sources']);

        $out = [];
        foreach ($widgets as $widget) {
            $id = (string) ($widget['id'] ?? $widget['public_id'] ?? count($out));
            $rendered = $this->render($widget, $period, $batch['results'], $dashboardSettings, $sources);
            $out[$id] = $rendered;
        }

        return [
            'widgets' => $out,
            'sources' => $sources->toArray(),
            'period'  => $period->toArray(),
        ];
    }

    /**
     * @param array<string, mixed>        $widget
     * @param array<string, MetricResult> $batch
     * @param array<string, mixed>        $dashboardSettings
     * @return array<string, mixed>
     */
    public function render(array $widget, Period $period, array $batch, array $dashboardSettings, Sources $sources): array
    {
        $type = (string) ($widget['widget_type'] ?? '');
        $config = is_array($widget['config'] ?? null) ? $widget['config'] : [];
        $metricId = is_string($config['metric_id'] ?? null) ? $config['metric_id'] : null;

        // A widget-level period override. Applied here and ANNOUNCED in the
        // payload, so the card can say it is showing a different window from
        // the rest of the board.
        $widgetPeriod = $this->widgetPeriod($period, $config);
        $overridden = $widgetPeriod !== $period;

        $base = [
            'widget_id'   => $widget['id'] ?? null,
            'widget_type' => $type,
            'title'       => $widget['title'] ?? '',
            'description' => $widget['description'] ?? '',
            'period'      => $widgetPeriod->toArray(),
            'period_overridden' => $overridden,
        ];

        if ($type === 'text') {
            return $base + ['status' => 'ok', 'text' => (string) ($config['text'] ?? '')];
        }

        if ($type === 'source_status') {
            return $base + ['status' => 'ok', 'sources' => $sources->toArray()];
        }

        if ($metricId === null) {
            return $base + ['status' => 'error', 'message' => 'This widget has no metric configured.'];
        }

        $filters = $this->filters($dashboardSettings) + WidgetSchema::filters($config['filters'] ?? null);

        return match ($type) {
            'kpi', 'comparison' => $base + $this->flat($metricId, $widgetPeriod, $batch, $filters, $overridden, $config),
            'line', 'area', 'bar' => $base + $this->series($metricId, $widgetPeriod, $config, $filters, $sources),
            'stacked_bar', 'ranking', 'donut', 'table' => $base + $this->breakdown($metricId, $widgetPeriod, $config, $filters, $sources),
            'ageing'     => $base + $this->ageing($metricId, $widgetPeriod, $filters, $sources),
            'forecast'   => $base + $this->forecast($metricId, $widgetPeriod, $config, $filters, $sources),
            'ai_summary' => $base + $this->summaryInputs($metricId, $widgetPeriod, $batch, $config, $filters, $overridden),
            default      => $base + ['status' => 'error', 'message' => 'This widget type is not supported.'],
        };
    }

    /**
     * @param array<string, MetricResult> $batch
     * @param array<string, mixed>        $filters
     * @param array<string, mixed>        $config
     * @return array<string, mixed>
     */
    private function flat(string $metricId, Period $period, array $batch, array $filters, bool $overridden, array $config): array
    {
        // A card with its own period was not in the batch — it asked a different
        // question — so it is fetched on its own.
        $result = (!$overridden && isset($batch[$metricId]))
            ? $batch[$metricId]
            : ($this->query->metrics($period, [$metricId], $filters)['results'][$metricId] ?? null);

        if ($result === null) {
            return ['status' => 'error', 'message' => 'That metric is no longer in the catalogue.'];
        }

        $payload = $result->jsonSerialize();

        if (($config['show_target'] ?? false) && isset($config['target'])) {
            $payload['target'] = $config['target'];
            $payload['target_formatted'] = $result->format((string) $config['target']);
        }

        return ['status' => 'ok', 'metric' => $payload];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function series(string $metricId, Period $period, array $config, array $filters, Sources $sources): array
    {
        $grain = is_string($config['grain'] ?? null) ? $config['grain'] : $period->grain;
        $answer = $this->query->series($period->withGrain($grain), $metricId, $filters);
        $sources->merge($answer['sources']);

        if ($answer['result'] === null) {
            $definition = MetricCatalog::get($metricId) ?? CustomMetrics::definition($this->ctx, $metricId);
            $label = $definition?->label ?? $metricId;

            return [
                'status'  => 'unavailable',
                'message' => $label . ' is not reported over time by the product that owns it, so it cannot be charted. Show it as a KPI card instead.',
            ];
        }

        return ['status' => 'ok', 'metric' => $answer['result']->jsonSerialize(), 'chart_type' => $config['chart_type'] ?? 'line'];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function breakdown(string $metricId, Period $period, array $config, array $filters, Sources $sources): array
    {
        $dimension = is_string($config['dimension'] ?? null) ? $config['dimension'] : '';
        if ($dimension === '') {
            return ['status' => 'error', 'message' => 'This widget needs a dimension.'];
        }

        $answer = $this->query->breakdown($period, $metricId, $dimension, $filters);
        $sources->merge($answer['sources']);

        if ($answer['result'] === null) {
            $definition = MetricCatalog::get($metricId);

            return [
                'status'  => 'unavailable',
                'message' => ($definition?->label ?? $metricId) . ' cannot be split by ' . strtolower(MetricCatalog::DIMENSIONS[$dimension] ?? $dimension)
                    . ' by the product that owns it.',
            ];
        }

        $payload = $answer['result']->jsonSerialize();

        // Sorting and the record limit are applied here rather than being asked
        // of the source: a source that returns its own top ten has already
        // decided, and re-sorting its answer keeps the coverage warning honest.
        $rows = $payload['breakdown'];
        $sort = (string) ($config['sort'] ?? 'value');
        $order = (string) ($config['order'] ?? 'desc');
        usort($rows, static function (array $a, array $b) use ($sort, $order): int {
            $comparison = $sort === 'label'
                ? strcasecmp((string) $a['label'], (string) $b['label'])
                : \Aicountly\Api\Support\Decimal::cmp((string) ($a['value'] ?? '0'), (string) ($b['value'] ?? '0'));

            return $order === 'asc' ? $comparison : -$comparison;
        });

        $limit = (int) ($config['limit'] ?? 10);
        if (count($rows) > $limit) {
            $payload['warnings'][] = 'Showing ' . $limit . ' of ' . count($rows) . ' rows.';
            $rows = array_slice($rows, 0, $limit);
        }
        $payload['breakdown'] = array_values($rows);

        return ['status' => 'ok', 'metric' => $payload, 'dimension' => $dimension, 'chart_type' => $config['chart_type'] ?? 'ranking'];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function ageing(string $metricId, Period $period, array $filters, Sources $sources): array
    {
        $answer = $this->query->breakdown($period, $metricId, 'ageing_bucket', $filters);
        $sources->merge($answer['sources']);

        if ($answer['result'] === null) {
            return [
                'status'  => 'unavailable',
                'message' => (MetricCatalog::get($metricId)?->label ?? $metricId) . ' is not reported by age by the product that owns it.',
            ];
        }

        return ['status' => 'ok', 'metric' => $answer['result']->jsonSerialize()];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function forecast(string $metricId, Period $period, array $config, array $filters, Sources $sources): array
    {
        $forecast = new ForecastService($this->ctx, $this->auth, $this->query);
        $answer = $forecast->forMetric(
            $metricId,
            $period,
            (string) ($config['method'] ?? 'moving_average'),
            (int) ($config['horizon'] ?? 3),
            isset($config['scenario_adjustment_percent']) ? (string) $config['scenario_adjustment_percent'] : null,
            $filters,
        );
        $sources->merge($answer['sources']);

        return $answer['payload'];
    }

    /**
     * The grounded figures an AI summary card is written from.
     *
     * The card does NOT call a model here. A dashboard that made a model call
     * per render — and again on every resize and drag — would be slow and
     * expensive and would say something slightly different each time. The card
     * returns its figures; the browser asks for the sentence once, when the
     * user asks for it.
     *
     * @param array<string, MetricResult> $batch
     * @param array<string, mixed>        $config
     * @param array<string, mixed>        $filters
     * @return array<string, mixed>
     */
    private function summaryInputs(string $metricId, Period $period, array $batch, array $config, array $filters, bool $overridden): array
    {
        $ids = array_values(array_unique(array_merge(
            [$metricId],
            array_values(array_filter((array) ($config['extra_metrics'] ?? []), 'is_string')),
        )));

        $metrics = [];
        $missing = [];
        foreach ($ids as $id) {
            $result = (!$overridden && isset($batch[$id]))
                ? $batch[$id]
                : ($this->query->metrics($period, [$id], $filters)['results'][$id] ?? null);
            if ($result === null) {
                continue;
            }
            $metrics[] = $result->jsonSerialize();
            if (!$result->isAvailable()) {
                $missing[] = MetricCatalog::get($id)?->label ?? $id;
            }
        }

        return [
            'status'  => 'ok',
            'metrics' => $metrics,
            // The card can write a rules-based line without any model at all,
            // which is what it shows until somebody asks for more.
            'missing' => $missing,
        ];
    }

    private function isFlat(string $type): bool
    {
        return in_array($type, ['kpi', 'comparison', 'ai_summary'], true);
    }

    /** @param array<string, mixed> $config */
    private function widgetPeriod(Period $period, array $config): Period
    {
        $override = $config['period_override'] ?? null;
        if (!is_array($override) || ($override['enabled'] ?? false) !== true) {
            return $period;
        }

        return Period::fromConfig($override + ['grain' => $period->grain, 'compare' => $period->comparisonMode], $period->grain);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function filters(array $settings): array
    {
        return WidgetSchema::filters($settings['filters'] ?? null);
    }
}
