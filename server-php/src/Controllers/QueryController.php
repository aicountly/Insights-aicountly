<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Dashboards\WidgetSchema;
use Aicountly\Api\Http;
use Aicountly\Api\Metrics\MetricCatalog;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Period;

/**
 * Asking for figures, directly.
 *
 * This is what the builder's preview, the ad-hoc explorer and the overview page
 * all call. Every answer carries its provenance, its coverage and its warnings,
 * because a figure without those is a figure somebody will quote in a meeting.
 */
final class QueryController extends Controller
{
    /** How many metrics one request may ask for. A board, not a data dump. */
    private const MAX_METRICS = 24;

    public static function metrics(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'metric.view');

        $ids = array_values(array_filter(Http::arrayParam('metrics'), 'is_string'));
        if ($ids === []) {
            Http::validationFailed('Name at least one metric.', ['field' => 'metrics']);
        }
        if (count($ids) > self::MAX_METRICS) {
            Http::validationFailed('Ask for up to ' . self::MAX_METRICS . ' metrics at a time.', ['field' => 'metrics', 'limit' => self::MAX_METRICS]);
        }

        $period = Period::fromRequest();
        $filters = WidgetSchema::filters(Http::objectParam('filters'));

        $answer = self::query($ctx, $auth)->metrics($period, $ids, $filters);

        Http::data([
            'period'  => $period->toArray(),
            'scope'   => $ctx->asQuery(),
            'metrics' => array_map(static fn ($result) => $result->jsonSerialize(), $answer['results']),
            'sources' => $answer['sources']->toArray(),
            // Named rather than dropped: a client asking for a metric that does
            // not exist has a bug, and a silent empty result hides it.
            'unknown' => $answer['unknown'],
        ]);
    }

    public static function series(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'metric.view');

        $metricId = (string) (Http::param('metric') ?? '');
        if ($metricId === '') {
            Http::validationFailed('Which metric?', ['field' => 'metric']);
        }

        $period = Period::fromRequest();
        $filters = WidgetSchema::filters(Http::objectParam('filters'));

        $answer = self::query($ctx, $auth)->series($period, $metricId, $filters);

        if ($answer['result'] === null) {
            Http::data([
                'period'  => $period->toArray(),
                'metric'  => null,
                'sources' => $answer['sources']->toArray(),
                'message' => (MetricCatalog::get($metricId)?->label ?? $metricId)
                    . ' is not reported over time by the product that owns it.',
            ]);
        }

        Http::data([
            'period'  => $period->toArray(),
            'metric'  => $answer['result']->jsonSerialize(),
            'sources' => $answer['sources']->toArray(),
        ]);
    }

    public static function breakdown(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'metric.view');

        $metricId = (string) (Http::param('metric') ?? '');
        $dimension = (string) (Http::param('dimension') ?? '');
        if ($metricId === '' || $dimension === '') {
            Http::validationFailed('A breakdown needs a metric and a dimension.', ['field' => $metricId === '' ? 'metric' : 'dimension']);
        }

        $period = Period::fromRequest();
        $filters = WidgetSchema::filters(Http::objectParam('filters'));

        $answer = self::query($ctx, $auth)->breakdown($period, $metricId, $dimension, $filters);

        if ($answer['result'] === null) {
            Http::data([
                'period'  => $period->toArray(),
                'metric'  => null,
                'sources' => $answer['sources']->toArray(),
                'message' => (MetricCatalog::get($metricId)?->label ?? $metricId) . ' cannot be split by '
                    . strtolower(MetricCatalog::DIMENSIONS[$dimension] ?? $dimension) . '.',
            ]);
        }

        Http::data([
            'period'    => $period->toArray(),
            'dimension' => $dimension,
            'metric'    => $answer['result']->jsonSerialize(),
            'sources'   => $answer['sources']->toArray(),
        ]);
    }

    /**
     * Where a reader goes to see the records behind a figure.
     *
     * Returns a DESCRIPTION — product, route, parameters — never a URL built
     * from anything the caller sent. The browser resolves it against the
     * product's own origin, which is how a drill-down cannot become an open
     * redirect.
     */
    public static function drilldown(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'metric.view');

        $metricId = (string) (Http::param('metric') ?? '');
        if ($metricId === '') {
            Http::validationFailed('Which metric?', ['field' => 'metric']);
        }

        $period = Period::fromRequest();
        $target = self::query($ctx, $auth)->drilldown($period, $metricId, WidgetSchema::filters(Http::objectParam('filters')));

        if ($target === null) {
            Http::data([
                'target'  => null,
                'message' => 'There is no verified screen in the owning product to open for this figure.',
            ]);
        }

        Http::data(['target' => $target]);
    }
}
