<?php

declare(strict_types=1);

namespace Aicountly\Api\Adapters;

use Aicountly\Api\Context;
use Aicountly\Api\Metrics\MetricResult;
use Aicountly\Api\Support\Period;
use Aicountly\Api\Support\Sources;

/**
 * What every data source must be able to answer.
 *
 * An adapter is the only thing in Insights that knows the shape of another
 * product's replies. Nothing above this layer sees a raw payload, and nothing
 * below it decides what a metric means — the catalogue does that.
 *
 * A COMPILED ADAPTER IS NOT A WORKING INTEGRATION. `status()` is how the
 * product says which of the two it is holding, per deployment and per viewer:
 * a source can be enabled and unreachable, reachable and forbidden to this
 * person, or answering perfectly. All three are different states with different
 * things the reader can do about them, so all three are reported.
 */
interface SourceAdapter
{
    /** Stable product key: books | inventory | sales | purchases | billing | pos. */
    public function product(): string;

    /** What a person calls it. */
    public function label(): string;

    /**
     * Metric ids this adapter can answer.
     *
     * @return list<string>
     */
    public function metrics(): array;

    /**
     * Fetch a set of metrics for one scope and period.
     *
     * Implementations make as few round trips as the source allows — one call
     * that answers eight KPIs, not eight calls. A metric this adapter cannot
     * answer must still appear in the result, as `unavailable` with a reason.
     *
     * @param list<string>         $metricIds
     * @param array<string, mixed> $filters
     * @return array<string, MetricResult>
     */
    public function fetch(Context $ctx, Period $period, array $metricIds, array $filters, Sources $sources): array;

    /**
     * A metric bucketed over time.
     *
     * Returns null when the source has no trend for it, which is a legitimate
     * answer: a closing balance has no daily series unless the source computes
     * one, and inventing it by dividing the period is a fabrication.
     *
     * @param array<string, mixed> $filters
     */
    public function series(Context $ctx, Period $period, string $metricId, array $filters, Sources $sources): ?MetricResult;

    /**
     * A metric split by one dimension.
     *
     * @param array<string, mixed> $filters
     */
    public function breakdown(Context $ctx, Period $period, string $metricId, string $dimension, array $filters, Sources $sources): ?MetricResult;

    /**
     * Whether this deployment can reach the source, and what this viewer may
     * see there.
     *
     * @return array{
     *     product:string, label:string, configured:bool, reachable:bool,
     *     permitted:bool, status:string, message:?string, metrics:list<string>,
     *     dimensions:list<string>, checked_at:string, base:?string
     * }
     */
    public function status(Context $ctx): array;

    /**
     * Where a reader goes to see the records behind a figure.
     *
     * Returns a description of the destination — product, route, parameters —
     * and never a URL assembled from user input. The browser resolves it
     * against the product's own origin.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function drilldown(Context $ctx, Period $period, string $metricId, array $filters): ?array;
}
