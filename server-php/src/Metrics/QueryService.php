<?php

declare(strict_types=1);

namespace Aicountly\Api\Metrics;

use Aicountly\Api\Adapters\AdapterRegistry;
use Aicountly\Api\Adapters\BooksAdapter;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Support\Period;
use Aicountly\Api\Support\Sources;

/**
 * One question, answered from live sources.
 *
 * WHAT THIS SERVICE IS RESPONSIBLE FOR, and it is the whole of it:
 *
 *   1. Refusing a metric that is not in the catalogue, before anything is
 *      fetched. An id a caller made up is a 422, not an empty card.
 *   2. Routing each metric to the one adapter that owns it, so a metric is
 *      fetched from exactly one place and there is never a second opinion.
 *   3. Fanning out to the adapters and letting each one batch its own calls.
 *   4. Computing derived metrics from the results, in dependency order, with
 *      exact arithmetic and without substituting zero for anything missing.
 *   5. Attaching the comparison window, and only where comparing is meaningful.
 *   6. Recording which sources answered, so the screen can say so.
 *
 * WHAT IT DOES NOT DO: persist anything. Not one business figure from this
 * service reaches the database. Insights stores dashboard configuration and
 * reads the numbers live, every time — that is the architecture, and a cache
 * table here would quietly undo it.
 */
final class QueryService
{
    private readonly AdapterRegistry $registry;

    /** @var array<string, MetricResult> */
    private array $memo = [];

    public function __construct(
        private readonly Auth $auth,
        private readonly Context $ctx,
    ) {
        $this->registry = new AdapterRegistry($auth);
    }

    public function registry(): AdapterRegistry
    {
        return $this->registry;
    }

    /**
     * Answer a set of metrics.
     *
     * @param list<string>         $metricIds
     * @param array<string, mixed> $filters
     * @return array{results: array<string, MetricResult>, sources: Sources, unknown: list<string>}
     */
    public function metrics(Period $period, array $metricIds, array $filters = [], bool $withComparison = true): array
    {
        $sources = new Sources();
        $requested = array_values(array_unique(array_filter($metricIds, 'is_string')));

        $unknown = array_values(array_filter($requested, static fn (string $id) => !MetricCatalog::exists($id) && !CustomMetrics::exists($id)));
        $known = array_values(array_diff($requested, $unknown));

        // Custom KPIs are expanded to the catalogue metrics they read, so a
        // board asking for one custom KPI and one of its inputs still costs one
        // fetch of that input.
        [$direct, $derived, $custom] = $this->classify($known);

        $needed = $direct;
        foreach ($derived as $metricId) {
            foreach (MetricCatalog::require($metricId)->dependsOn as $dependency) {
                $needed[] = $dependency;
            }
        }
        foreach ($custom as $metricId => $expression) {
            foreach ($expression->references() as $reference) {
                $needed[] = $reference;
            }
        }
        // A derived metric may depend on another derived metric.
        $needed = $this->expandDerived(array_values(array_unique($needed)));

        $fetched = $this->fetchDirect($period, $needed, $filters, $sources);

        // Derived metrics, in dependency order.
        foreach ($this->orderDerived(array_values(array_unique(array_merge($derived, array_filter($needed, static fn (string $id) => (MetricCatalog::get($id)?->dependsOn ?? []) !== []))))) as $metricId) {
            $fetched[$metricId] = $this->derive($period, $metricId, $fetched);
        }

        foreach ($custom as $metricId => $expression) {
            $fetched[$metricId] = $this->evaluateCustom($period, $metricId, $expression, $fetched);
        }

        if ($withComparison && $period->hasComparison()) {
            $this->attachComparisons($period, $fetched, $direct, $derived, $custom, $filters);
        }

        $results = [];
        foreach ($requested as $metricId) {
            if (isset($fetched[$metricId])) {
                $results[$metricId] = $fetched[$metricId];
            }
        }

        return ['results' => $results, 'sources' => $sources, 'unknown' => $unknown];
    }

    /**
     * One metric, bucketed over time.
     *
     * @param array<string, mixed> $filters
     * @return array{result: ?MetricResult, sources: Sources}
     */
    public function series(Period $period, string $metricId, array $filters = []): array
    {
        $sources = new Sources();
        $definition = MetricCatalog::get($metricId);
        if ($definition === null) {
            return ['result' => null, 'sources' => $sources];
        }

        $adapter = $this->registry->get($definition->owningProduct);
        $result = $adapter?->series($this->ctx, $period, $metricId, $filters, $sources);

        return ['result' => $result, 'sources' => $sources];
    }

    /**
     * One metric split by one dimension.
     *
     * @param array<string, mixed> $filters
     * @return array{result: ?MetricResult, sources: Sources}
     */
    public function breakdown(Period $period, string $metricId, string $dimension, array $filters = []): array
    {
        $sources = new Sources();
        $definition = MetricCatalog::get($metricId);
        if ($definition === null) {
            return ['result' => null, 'sources' => $sources];
        }
        if (!$definition->supportsDimension($dimension)) {
            return ['result' => null, 'sources' => $sources];
        }

        $adapter = $this->registry->get($definition->owningProduct);
        $result = $adapter?->breakdown($this->ctx, $period, $metricId, $dimension, $filters, $sources);

        return ['result' => $result, 'sources' => $sources];
    }

    /**
     * Where a reader goes to see the records behind a figure.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function drilldown(Period $period, string $metricId, array $filters = []): ?array
    {
        $definition = MetricCatalog::get($metricId);
        if ($definition === null) {
            return null;
        }

        return $this->registry->get($definition->owningProduct)?->drilldown($this->ctx, $period, $metricId, $filters);
    }

    // -----------------------------------------------------------------------

    /**
     * @param list<string> $metricIds
     * @return array{0: list<string>, 1: list<string>, 2: array<string, Expression>}
     */
    private function classify(array $metricIds): array
    {
        $direct = [];
        $derived = [];
        $custom = [];

        foreach ($metricIds as $metricId) {
            $definition = MetricCatalog::get($metricId);
            if ($definition !== null) {
                if ($definition->dependsOn !== []) {
                    $derived[] = $metricId;
                } else {
                    $direct[] = $metricId;
                }
                continue;
            }

            $expression = CustomMetrics::expression($this->ctx, $metricId);
            if ($expression !== null) {
                $custom[$metricId] = $expression;
            }
        }

        return [$direct, $derived, $custom];
    }

    /**
     * Pull in the dependencies of derived metrics, transitively.
     *
     * @param list<string> $metricIds
     * @return list<string>
     */
    private function expandDerived(array $metricIds): array
    {
        $seen = [];
        $queue = $metricIds;
        $guard = 0;

        while ($queue !== [] && $guard++ < 200) {
            $metricId = array_shift($queue);
            if (isset($seen[$metricId])) {
                continue;
            }
            $seen[$metricId] = true;
            foreach (MetricCatalog::get($metricId)?->dependsOn ?? [] as $dependency) {
                $queue[] = $dependency;
            }
        }

        return array_keys($seen);
    }

    /**
     * Derived metrics, dependencies first.
     *
     * @param list<string> $metricIds
     * @return list<string>
     */
    private function orderDerived(array $metricIds): array
    {
        $derived = array_values(array_filter($metricIds, static fn (string $id) => (MetricCatalog::get($id)?->dependsOn ?? []) !== []));
        $ordered = [];
        $placed = [];
        $guard = 0;

        while ($derived !== [] && $guard++ < 100) {
            foreach ($derived as $index => $metricId) {
                $pending = array_filter(
                    MetricCatalog::require($metricId)->dependsOn,
                    static fn (string $dep) => (MetricCatalog::get($dep)?->dependsOn ?? []) !== [] && !isset($placed[$dep]),
                );
                if ($pending === []) {
                    $ordered[] = $metricId;
                    $placed[$metricId] = true;
                    unset($derived[$index]);
                }
            }
            $derived = array_values($derived);
        }

        // Anything left is part of a cycle. The catalogue is code, so this is a
        // programming error rather than user input, and it is logged rather
        // than surfaced.
        foreach ($derived as $metricId) {
            error_log('[insights][metrics] derived metric ' . $metricId . ' appears to be part of a dependency cycle.');
        }

        return $ordered;
    }

    /**
     * @param list<string>         $metricIds
     * @param array<string, mixed> $filters
     * @return array<string, MetricResult>
     */
    private function fetchDirect(Period $period, array $metricIds, array $filters, Sources $sources): array
    {
        $directIds = array_values(array_filter(
            $metricIds,
            static fn (string $id) => MetricCatalog::exists($id) && MetricCatalog::require($id)->dependsOn === [],
        ));

        $out = [];
        $stillNeeded = [];

        foreach ($directIds as $metricId) {
            $memoKey = $this->memoKey($period, $metricId, $filters);
            if (isset($this->memo[$memoKey])) {
                $out[$metricId] = $this->memo[$memoKey];
                continue;
            }
            $stillNeeded[] = $metricId;
        }

        foreach ($this->registry->route($stillNeeded) as $product => $mine) {
            $adapter = $this->registry->get($product);
            if ($adapter === null) {
                continue;
            }
            foreach ($adapter->fetch($this->ctx, $period, $mine, $filters, $sources) as $metricId => $result) {
                $out[$metricId] = $result;
                $this->memo[$this->memoKey($period, $metricId, $filters)] = $result;
            }
        }

        // A metric nobody claimed — because it is declared in the catalogue and
        // has no binding yet. It is reported as unavailable with the reason,
        // which is the whole point of declaring it.
        foreach ($stillNeeded as $metricId) {
            if (isset($out[$metricId])) {
                continue;
            }
            $definition = MetricCatalog::require($metricId);
            $out[$metricId] = MetricResult::unavailable(
                $definition,
                $this->ctx,
                $period,
                $definition->label . ' is not available from any connected product yet. It is listed here so the gap is visible rather than silent.',
            )->withWarning('No verified endpoint binds ' . $definition->id . '. See docs/INTEGRATION_MAP.md.');
            $sources->notRequested($definition->owningProduct, ucfirst($definition->owningProduct), $definition->label . ' has no verified endpoint in this fleet.');
        }

        return $out;
    }

    /**
     * @param array<string, MetricResult> $fetched
     */
    private function derive(Period $period, string $metricId, array $fetched): MetricResult
    {
        $definition = MetricCatalog::require($metricId);

        $values = [];
        $missing = [];
        foreach ($definition->dependsOn as $dependency) {
            $result = $fetched[$dependency] ?? null;
            $value = $result?->numericValue();
            $values[$dependency] = $value;
            if ($value !== null) {
                continue;
            }

            // Name the ROOT cause, not the nearest one. "Gross margin needs
            // gross profit" sends somebody looking for a metric that is also
            // derived; "gross margin needs cost of goods sold" sends them to
            // the integration that is actually missing.
            foreach ($this->rootCauses($dependency, $fetched) as $label) {
                $missing[] = $label;
            }
        }
        $missing = array_values(array_unique($missing));

        if ($missing !== []) {
            // THE RULE: a missing input makes the derived figure unavailable.
            // Not zero. Gross profit with no COGS is not gross profit.
            $result = MetricResult::unavailable(
                $definition,
                $this->ctx,
                $period,
                $definition->label . ' needs ' . self::joinWords($missing) . ', which could not be read. It is unavailable rather than zero.',
            );
            foreach ($definition->dependsOn as $dependency) {
                $result->withProvenance(
                    MetricCatalog::get($dependency)?->owningProduct ?? 'insights',
                    'derived from ' . $dependency,
                    'n/a',
                );
            }

            return $result;
        }

        $computed = $this->computeDerived($metricId, $values);

        if ($computed === null) {
            return MetricResult::notApplicable(
                $definition,
                $this->ctx,
                $period,
                $definition->label . ' is not applicable for this period: the figure it divides by is zero.',
            );
        }

        $result = MetricResult::available($definition, $computed, $this->ctx, $period);
        $coveragePartial = false;
        foreach ($definition->dependsOn as $dependency) {
            $source = $fetched[$dependency] ?? null;
            $result->withProvenance(
                MetricCatalog::get($dependency)?->owningProduct ?? 'insights',
                'derived from ' . $dependency,
                'computed in Insights',
            );
            if ($source !== null && $source->jsonSerialize()['coverage'] === MetricResult::COVERAGE_PARTIAL) {
                $coveragePartial = true;
            }
        }

        if ($coveragePartial) {
            $result->withPartialCoverage('One of the figures this is calculated from covers part of the data only, so this figure does too.');
        }

        return $result;
    }

    /**
     * The metrics at the bottom of a failed derivation.
     *
     * Walks down through derived dependencies until it reaches ones that are
     * bound to a source, and returns the labels of those that could not be
     * read. A derived metric that failed for its own reason — a zero
     * denominator — is named itself, because that is the root cause there.
     *
     * @param array<string, MetricResult> $fetched
     * @return list<string>
     */
    private function rootCauses(string $metricId, array $fetched, int $depth = 0): array
    {
        $definition = MetricCatalog::get($metricId);
        if ($definition === null || $depth > 6) {
            return [$metricId];
        }

        if ($definition->dependsOn === []) {
            return [$definition->label];
        }

        $causes = [];
        foreach ($definition->dependsOn as $dependency) {
            if (($fetched[$dependency] ?? null)?->numericValue() !== null) {
                continue;
            }
            foreach ($this->rootCauses($dependency, $fetched, $depth + 1) as $label) {
                $causes[] = $label;
            }
        }

        // Every input present and the metric still has no value: the failure is
        // this metric's own, so it is its own root cause.
        return $causes === [] ? [$definition->label] : $causes;
    }

    /**
     * The derived formulas, written out.
     *
     * Deliberately explicit rather than expression-driven: these are the
     * definitions the product ships with, they are the ones an accountant will
     * argue about, and they should be readable as arithmetic in one place.
     *
     * @param array<string, ?string> $values
     */
    private function computeDerived(string $metricId, array $values): ?string
    {
        $get = static fn (string $id): string => (string) $values[$id];

        return match ($metricId) {
            'finance.gross_profit' => \Aicountly\Api\Support\Decimal::sub(
                $get('finance.net_revenue'),
                $get('finance.cogs'),
            ),
            'finance.gross_margin' => \Aicountly\Api\Support\Decimal::percentOf(
                $get('finance.gross_profit'),
                $get('finance.net_revenue'),
                2,
            ),
            'finance.collection_efficiency' => \Aicountly\Api\Support\Decimal::percentOf(
                $get('finance.collections'),
                $get('finance.net_revenue'),
                1,
            ),
            'finance.overdue_share' => \Aicountly\Api\Support\Decimal::percentOf(
                $get('finance.overdue_receivables'),
                $get('finance.receivables'),
                1,
            ),
            'finance.working_capital_tied' => \Aicountly\Api\Support\Decimal::add(
                $get('inventory.stock_value'),
                $get('finance.receivables'),
            ),
            default => null,
        };
    }

    /**
     * @param array<string, MetricResult> $fetched
     */
    private function evaluateCustom(Period $period, string $metricId, Expression $expression, array $fetched): MetricResult
    {
        $definition = CustomMetrics::definition($this->ctx, $metricId);
        if ($definition === null) {
            return MetricResult::unavailable(
                new MetricDefinition($metricId, $metricId, 'A custom KPI that could not be loaded.', 'insights', 'custom', '', 'derived', 'currency', 2, [], [], 'derived', []),
                $this->ctx,
                $period,
                'That KPI could not be loaded.',
            );
        }

        $values = [];
        foreach ($expression->references() as $reference) {
            $values[$reference] = ($fetched[$reference] ?? null)?->numericValue();
        }

        $outcome = $expression->evaluate($values);

        $result = match ($outcome['status']) {
            MetricResult::AVAILABLE      => MetricResult::available($definition, (string) $outcome['value'], $this->ctx, $period),
            MetricResult::NOT_APPLICABLE => MetricResult::notApplicable($definition, $this->ctx, $period, (string) $outcome['reason']),
            default                      => MetricResult::unavailable($definition, $this->ctx, $period, (string) $outcome['reason']),
        };

        foreach ($expression->references() as $reference) {
            $result->withProvenance(
                MetricCatalog::get($reference)?->owningProduct ?? 'insights',
                'custom KPI reads ' . $reference,
                'computed in Insights',
            );
        }

        return $result->withWarning('Custom KPI: ' . $expression->source);
    }

    /**
     * Fetch the comparison window and attach it.
     *
     * @param array<string, MetricResult> $fetched
     * @param list<string>                $direct
     * @param list<string>                $derived
     * @param array<string, Expression>   $custom
     * @param array<string, mixed>        $filters
     */
    private function attachComparisons(Period $period, array &$fetched, array $direct, array $derived, array $custom, array $filters): void
    {
        $comparison = $period->comparison();
        if ($comparison === null) {
            return;
        }

        $needed = $direct;
        foreach ($derived as $metricId) {
            foreach (MetricCatalog::require($metricId)->dependsOn as $dependency) {
                $needed[] = $dependency;
            }
        }
        foreach ($custom as $expression) {
            foreach ($expression->references() as $reference) {
                $needed[] = $reference;
            }
        }
        $needed = $this->expandDerived(array_values(array_unique($needed)));

        $sink = new Sources();
        $previous = $this->fetchDirect($comparison, $needed, $filters, $sink);

        foreach ($this->orderDerived($needed) as $metricId) {
            $previous[$metricId] = $this->derive($comparison, $metricId, $previous);
        }
        foreach ($custom as $metricId => $expression) {
            $previous[$metricId] = $this->evaluateCustom($comparison, $metricId, $expression, $previous);
        }

        foreach ($fetched as $metricId => $result) {
            $previousValue = ($previous[$metricId] ?? null)?->numericValue();
            $result->withComparison($previousValue);
        }
    }

    /** @param array<string, mixed> $filters */
    private function memoKey(Period $period, string $metricId, array $filters): string
    {
        ksort($filters);

        return $metricId . '|' . $this->ctx->key() . '|' . $period->from . '|' . $period->to . '|' . md5((string) json_encode($filters));
    }

    /** @param list<string> $words */
    private static function joinWords(array $words): string
    {
        if (count($words) === 1) {
            return $words[0];
        }
        $last = array_pop($words);

        return implode(', ', $words) . ' and ' . $last;
    }
}
