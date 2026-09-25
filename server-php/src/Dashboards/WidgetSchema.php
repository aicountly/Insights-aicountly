<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Context;
use Aicountly\Api\Metrics\CustomMetrics;
use Aicountly\Api\Metrics\MetricCatalog;

/**
 * What a widget is allowed to say.
 *
 * THIS IS A SECURITY BOUNDARY, not a convenience. A dashboard is shared, and
 * its configuration is rendered in somebody else's browser — so the only things
 * a widget may carry are values from fixed lists and numbers within fixed
 * ranges. There is no free SQL, no JavaScript, no HTML, no template string and
 * no API URL anywhere in a widget config, and none can be added by a caller:
 * anything not named below is DROPPED rather than passed through, so a field
 * nobody validated cannot reach storage by being sent.
 *
 * It is also where a configuration stops being plausible and starts being
 * meaningful. A bar chart of a metric that has no dimensions, a daily grain on
 * a metric reported monthly, a donut of a balance — each is rejected with a
 * sentence saying why, because the alternative is a widget that saves happily
 * and renders an apology.
 *
 * The AI writes configuration through this same validator. A model that
 * produced something outside the schema gets the same refusal a hand-edited
 * request would, which is what makes "the AI cannot escape its scope" a
 * property of the code rather than of the prompt.
 */
final class WidgetSchema
{
    /**
     * Widget types, and what each one needs.
     *
     * A type not listed here does not exist, and the picker is built from this
     * list — so the UI cannot offer something the backend will refuse.
     *
     * @var array<string, array{label:string, needs_metric:bool, needs_dimension:bool, series:bool, description:string}>
     */
    public const TYPES = [
        'kpi' => [
            'label' => 'KPI card', 'needs_metric' => true, 'needs_dimension' => false, 'series' => false,
            'description' => 'One figure, with its change against the comparison period.',
        ],
        'line' => [
            'label' => 'Line chart', 'needs_metric' => true, 'needs_dimension' => false, 'series' => true,
            'description' => 'A metric over time.',
        ],
        'area' => [
            'label' => 'Area chart', 'needs_metric' => true, 'needs_dimension' => false, 'series' => true,
            'description' => 'A metric over time, filled.',
        ],
        'bar' => [
            'label' => 'Bar chart', 'needs_metric' => true, 'needs_dimension' => false, 'series' => true,
            'description' => 'A metric over time as columns.',
        ],
        'stacked_bar' => [
            'label' => 'Stacked bars', 'needs_metric' => true, 'needs_dimension' => true, 'series' => true,
            'description' => 'A metric over time, split by a dimension.',
        ],
        'ranking' => [
            'label' => 'Ranking', 'needs_metric' => true, 'needs_dimension' => true, 'series' => false,
            'description' => 'The leading entries of a dimension, as horizontal bars.',
        ],
        'donut' => [
            'label' => 'Donut', 'needs_metric' => true, 'needs_dimension' => true, 'series' => false,
            'description' => 'The share each part of a dimension holds of the whole.',
        ],
        'table' => [
            'label' => 'Table', 'needs_metric' => true, 'needs_dimension' => true, 'series' => false,
            'description' => 'The same figures as rows, sortable and exportable.',
        ],
        'ageing' => [
            'label' => 'Ageing buckets', 'needs_metric' => true, 'needs_dimension' => false, 'series' => false,
            'description' => 'A receivable, payable or stock balance split by age.',
        ],
        'comparison' => [
            'label' => 'Period comparison', 'needs_metric' => true, 'needs_dimension' => false, 'series' => false,
            'description' => 'This period beside the one before it.',
        ],
        'forecast' => [
            'label' => 'Forecast', 'needs_metric' => true, 'needs_dimension' => false, 'series' => true,
            'description' => 'History and projection, with the method and its assumptions stated.',
        ],
        'text' => [
            'label' => 'Note', 'needs_metric' => false, 'needs_dimension' => false, 'series' => false,
            'description' => 'A heading or a note. Plain text — no markup is rendered.',
        ],
        'ai_summary' => [
            'label' => 'AI summary', 'needs_metric' => true, 'needs_dimension' => false, 'series' => false,
            'description' => 'A short written summary of the metrics on this card, grounded in the figures shown.',
        ],
        'source_status' => [
            'label' => 'Source status', 'needs_metric' => false, 'needs_dimension' => false, 'series' => false,
            'description' => 'Which connected products answered, and when.',
        ],
    ];

    public const CHART_TYPES = ['line', 'area', 'bar', 'stacked_bar', 'ranking', 'donut'];

    public const BREAKPOINTS = ['desktop' => 12, 'tablet' => 6, 'mobile' => 1];

    /** Accessible chart inks, in order. Named, so a config carries a token and never a hex string. */
    public const TONES = ['brand', 'teal', 'indigo', 'amber', 'rose', 'slate'];

    public const MAX_TITLE = 120;
    public const MAX_DESCRIPTION = 400;
    public const MAX_TEXT = 2000;
    public const MAX_ROWS = 100;
    public const MAX_WIDGETS = 40;

    /**
     * Validate and NORMALISE one widget.
     *
     * Returns the cleaned widget, or throws with a message naming the field.
     *
     * @param array<string, mixed> $input
     * @return array{widget_type:string, title:string, description:string, config:array<string,mixed>, layout:array<string,mixed>}
     * @throws WidgetSchemaError
     */
    public static function widget(array $input, Context $ctx): array
    {
        $type = (string) ($input['widget_type'] ?? $input['type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            throw new WidgetSchemaError(
                'There is no widget type called "' . self::safe($type) . '". The types available are ' . implode(', ', array_keys(self::TYPES)) . '.',
                'widget_type',
            );
        }

        $spec = self::TYPES[$type];
        $rawConfig = is_array($input['config'] ?? null) ? $input['config'] : [];

        $config = [];

        if ($spec['needs_metric']) {
            $config['metric_id'] = self::metricId($rawConfig['metric_id'] ?? null, $ctx);
            $definition = self::resolve($config['metric_id'], $ctx);

            if ($spec['needs_dimension']) {
                $config['dimension'] = self::dimension($rawConfig['dimension'] ?? null, $definition, $type);
            }

            if ($spec['series']) {
                $config['grain'] = self::grain($rawConfig['grain'] ?? null, $definition);
            }

            // A balance has no meaningful sum over time, so a chart of one
            // would be a line through a set of unrelated snapshots.
            if ($definition !== null && $definition->isBalance && $spec['series'] && $type !== 'forecast') {
                throw new WidgetSchemaError(
                    $definition->label . ' is a balance at a date, not a total over a period, so it cannot be charted over time. Use a KPI card or an ageing widget.',
                    'metric_id',
                );
            }

            $config['comparison'] = self::oneOf($rawConfig['comparison'] ?? null, ['previous_period', 'previous_year', 'none'], 'previous_period');
            $config['precision'] = self::boundedInt($rawConfig['precision'] ?? null, 0, 6, $definition?->precision ?? 2);
            $config['show_target'] = (bool) ($rawConfig['show_target'] ?? false);
            $config['target'] = self::decimalOrNull($rawConfig['target'] ?? null);
        }

        if (in_array($type, self::CHART_TYPES, true) || $type === 'stacked_bar') {
            $config['chart_type'] = self::oneOf($rawConfig['chart_type'] ?? null, self::CHART_TYPES, $type);
            $config['show_legend'] = (bool) ($rawConfig['show_legend'] ?? true);
            $config['tone'] = self::oneOf($rawConfig['tone'] ?? null, self::TONES, 'brand');
        }

        if (in_array($type, ['ranking', 'table', 'donut'], true)) {
            $config['limit'] = self::boundedInt($rawConfig['limit'] ?? null, 1, self::MAX_ROWS, 10);
            $config['sort'] = self::oneOf($rawConfig['sort'] ?? null, ['value', 'label'], 'value');
            $config['order'] = self::oneOf($rawConfig['order'] ?? null, ['asc', 'desc'], 'desc');
        }

        if ($type === 'text') {
            // Plain text. The front end renders it as text, never as markup —
            // a "rich text" widget on a shared dashboard is a stored XSS with a
            // friendly name.
            $config['text'] = mb_substr(self::plain((string) ($rawConfig['text'] ?? '')), 0, self::MAX_TEXT);
        }

        if ($type === 'forecast') {
            $config['horizon'] = self::boundedInt($rawConfig['horizon'] ?? null, 1, 24, 3);
            $config['method'] = self::oneOf($rawConfig['method'] ?? null, ['moving_average', 'linear_trend', 'seasonal_naive'], 'moving_average');
            $config['scenario_adjustment_percent'] = self::boundedDecimal($rawConfig['scenario_adjustment_percent'] ?? null, '-100', '100');
        }

        if ($type === 'ai_summary') {
            $config['extra_metrics'] = self::metricList($rawConfig['extra_metrics'] ?? null, $ctx, 4);
        }

        $config['filters'] = self::filters($rawConfig['filters'] ?? null);

        // A widget-level period override. Visible on the card, because a card
        // silently showing a different period from the rest of the board is how
        // somebody compares two things that are not comparable.
        $config['period_override'] = self::periodOverride($rawConfig['period_override'] ?? null);

        return [
            'widget_type' => $type,
            'title'       => mb_substr(self::plain((string) ($input['title'] ?? '')), 0, self::MAX_TITLE),
            'description' => mb_substr(self::plain((string) ($input['description'] ?? '')), 0, self::MAX_DESCRIPTION),
            'config'      => $config,
            'layout'      => self::layout($input['layout'] ?? null, $type),
        ];
    }

    /**
     * Coordinates per breakpoint, clamped to the grid.
     *
     * A widget is stored with all three so a phone layout is a stored decision
     * rather than something recomputed — and therefore different — every time
     * the page is opened.
     *
     * @return array<string, array{x:int, y:int, w:int, h:int}>
     */
    public static function layout(mixed $input, string $type = 'kpi'): array
    {
        $input = is_array($input) ? $input : [];
        $out = [];

        foreach (self::BREAKPOINTS as $breakpoint => $columns) {
            $given = is_array($input[$breakpoint] ?? null) ? $input[$breakpoint] : [];

            $w = self::boundedInt($given['w'] ?? null, 1, $columns, self::defaultWidth($type, $columns));
            $x = self::boundedInt($given['x'] ?? null, 0, max(0, $columns - 1), 0);
            // A widget wider than the space left at its x would overhang the
            // grid, so it is pulled back rather than silently clipped.
            if ($x + $w > $columns) {
                $x = max(0, $columns - $w);
            }

            $out[$breakpoint] = [
                'x' => $x,
                'y' => self::boundedInt($given['y'] ?? null, 0, 999, 0),
                'w' => $w,
                'h' => self::boundedInt($given['h'] ?? null, 1, 24, self::defaultHeight($type)),
            ];
        }

        return $out;
    }

    private static function defaultWidth(string $type, int $columns): int
    {
        $desktop = match ($type) {
            'kpi', 'source_status' => 3,
            'text'                 => 12,
            'ranking', 'donut', 'ageing', 'comparison', 'ai_summary' => 6,
            default                => 8,
        };

        return max(1, (int) round($desktop * $columns / 12)) ?: 1;
    }

    private static function defaultHeight(string $type): int
    {
        return match ($type) {
            'kpi'           => 2,
            'text'          => 2,
            'source_status' => 3,
            'table'         => 6,
            default         => 4,
        };
    }

    /**
     * Dashboard-level settings.
     *
     * @param array<string, mixed>|null $input
     * @return array<string, mixed>
     */
    public static function dashboardSettings(mixed $input): array
    {
        $input = is_array($input) ? $input : [];

        return [
            'preset'     => self::oneOf($input['preset'] ?? null, \Aicountly\Api\Support\Period::PRESETS, 'this_month'),
            'from'       => self::date($input['from'] ?? null),
            'to'         => self::date($input['to'] ?? null),
            'grain'      => self::oneOf($input['grain'] ?? null, \Aicountly\Api\Support\Period::GRAINS, 'month'),
            'compare'    => self::oneOf($input['compare'] ?? null, \Aicountly\Api\Support\Period::COMPARISONS, 'previous_period'),
            // bo_id 0 means every branch. A dashboard does not store cmp_id or
            // fy_id: those come from the viewer's own context, so opening a
            // shared dashboard cannot move somebody into another company.
            'branch_id'  => self::boundedInt($input['branch_id'] ?? null, 0, PHP_INT_MAX, 0),
            'filters'    => self::filters($input['filters'] ?? null),
            'density'    => self::oneOf($input['density'] ?? null, ['comfortable', 'compact'], 'comfortable'),
        ];
    }

    /**
     * Filters a widget or dashboard may carry.
     *
     * An allowlist of identifier fields, each an integer or a short code. A
     * filter naming anything else is dropped — the source products take ids,
     * and a filter that carried free text would be free text on its way into
     * somebody else's query string.
     *
     * @return array<string, int|string>
     */
    public static function filters(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $out = [];

        foreach (['customer_id', 'supplier_id', 'item_id', 'item_group_id', 'warehouse_id', 'account_id', 'branch_id'] as $field) {
            $value = $input[$field] ?? null;
            if (is_numeric($value) && (int) $value > 0) {
                $out[$field] = (int) $value;
            }
        }

        $bucket = $input['ageing_bucket'] ?? null;
        if (is_string($bucket) && preg_match('/^[a-z0-9_]{1,32}$/', $bucket) === 1) {
            $out['ageing_bucket'] = $bucket;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function periodOverride(mixed $input): ?array
    {
        if (!is_array($input) || ($input['enabled'] ?? false) !== true) {
            return null;
        }

        return [
            'enabled' => true,
            'preset'  => self::oneOf($input['preset'] ?? null, \Aicountly\Api\Support\Period::PRESETS, 'this_month'),
            'from'    => self::date($input['from'] ?? null),
            'to'      => self::date($input['to'] ?? null),
        ];
    }

    private static function metricId(mixed $value, Context $ctx): string
    {
        $id = is_string($value) ? trim($value) : '';
        if ($id === '') {
            throw new WidgetSchemaError('Choose a metric for this widget.', 'metric_id');
        }
        if (self::resolve($id, $ctx) === null) {
            throw new WidgetSchemaError(
                'There is no metric called "' . self::safe($id) . '". Pick one from the catalogue.',
                'metric_id',
            );
        }

        return $id;
    }

    /**
     * @return list<string>
     */
    private static function metricList(mixed $value, Context $ctx, int $max): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $candidate) {
            if (!is_string($candidate) || self::resolve($candidate, $ctx) === null) {
                continue;
            }
            $out[] = $candidate;
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    private static function resolve(string $id, Context $ctx): ?\Aicountly\Api\Metrics\MetricDefinition
    {
        $catalogue = MetricCatalog::get($id);
        if ($catalogue !== null) {
            return $catalogue;
        }

        return CustomMetrics::exists($id) ? CustomMetrics::definition($ctx, $id) : null;
    }

    private static function dimension(mixed $value, ?\Aicountly\Api\Metrics\MetricDefinition $definition, string $type): string
    {
        $dimension = is_string($value) ? trim($value) : '';
        if ($dimension === '') {
            throw new WidgetSchemaError('A ' . self::TYPES[$type]['label'] . ' needs a dimension to split by.', 'dimension');
        }
        if (!isset(MetricCatalog::DIMENSIONS[$dimension])) {
            throw new WidgetSchemaError('There is no dimension called "' . self::safe($dimension) . '".', 'dimension');
        }
        if ($definition !== null && !$definition->supportsDimension($dimension)) {
            throw new WidgetSchemaError(
                $definition->label . ' cannot be split by ' . strtolower(MetricCatalog::DIMENSIONS[$dimension])
                    . '. It supports ' . (($definition->dimensions === []) ? 'no dimensions' : implode(', ', $definition->dimensions)) . '.',
                'dimension',
            );
        }

        return $dimension;
    }

    private static function grain(mixed $value, ?\Aicountly\Api\Metrics\MetricDefinition $definition): string
    {
        $grain = is_string($value) && $value !== '' ? $value : 'month';
        if (!in_array($grain, \Aicountly\Api\Support\Period::GRAINS, true)) {
            throw new WidgetSchemaError('"' . self::safe($grain) . '" is not a date grain.', 'grain');
        }
        if ($definition !== null && !$definition->supportsGrain($grain)) {
            throw new WidgetSchemaError(
                $definition->label . ' is not reported by ' . $grain . '. It supports ' . implode(', ', $definition->grains) . '.',
                'grain',
            );
        }

        return $grain;
    }

    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private static function boundedInt(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private static function decimalOrNull(mixed $value): ?string
    {
        return \Aicountly\Api\Support\Decimal::parse($value);
    }

    private static function boundedDecimal(mixed $value, string $min, string $max): ?string
    {
        $parsed = \Aicountly\Api\Support\Decimal::parse($value);
        if ($parsed === null) {
            return null;
        }
        if (\Aicountly\Api\Support\Decimal::cmp($parsed, $min) < 0) {
            return $min;
        }
        if (\Aicountly\Api\Support\Decimal::cmp($parsed, $max) > 0) {
            return $max;
        }

        return $parsed;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }

    /**
     * Strip control characters and collapse whitespace.
     *
     * NOT an HTML sanitiser, and deliberately not: nothing this product stores
     * is ever rendered as markup, so the right answer to "<script>" is to keep
     * it as the seven characters somebody typed and render them as text.
     */
    private static function plain(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value);

        return trim((string) preg_replace('/[ \t]+/u', ' ', $value));
    }

    /** A user-supplied token, safe to name in an error message. */
    private static function safe(string $value): string
    {
        return mb_substr(self::plain($value), 0, 60);
    }
}
