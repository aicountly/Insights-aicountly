<?php

declare(strict_types=1);

namespace Aicountly\Api\Adapters;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;
use Aicountly\Api\Metrics\MetricCatalog;
use Aicountly\Api\Metrics\MetricResult;
use Aicountly\Api\Support\Decimal;
use Aicountly\Api\Support\Payload;
use Aicountly\Api\Support\Period;
use Aicountly\Api\Support\Sources;

/**
 * Inventory, adapted once.
 *
 * VALUATION IS READ FROM THE SUMMARY, NOT THE ROWS. `GET v1/valuation` is a
 * paged list of every item, and it carries the company total in `meta.summary`
 * whatever page size was asked for. So the KPI read asks for ONE row and takes
 * the summary: a complete total for one row on the wire. Paging through forty
 * thousand items to add them up would be both slower and less correct, because
 * the last page of a live system is not the same instant as the first.
 *
 * A RANKING IS EXPLICITLY PARTIAL. `breakdown()` asks for a page sorted by
 * value, and the result says so — the top twenty items are the top twenty
 * items, not the stock.
 */
final class InventoryAdapter implements SourceAdapter
{
    public const LABEL = InventoryClient::LABEL;

    private const METRICS = [
        'inventory.stock_value',
        'inventory.stock_quantity',
        'inventory.item_count',
        'inventory.ageing_value',
    ];

    /** @var array<string, array{ok:bool, status:int, body:?array, error:?string}> */
    private array $memo = [];

    public function __construct(private readonly Auth $auth)
    {
    }

    public function product(): string
    {
        return 'inventory';
    }

    public function label(): string
    {
        return self::LABEL;
    }

    /** @return list<string> */
    public function metrics(): array
    {
        return self::METRICS;
    }

    private function client(): InventoryClient
    {
        return (new InventoryClient())->withSession($this->auth->sesKey());
    }

    /**
     * @param list<string>         $metricIds
     * @param array<string, mixed> $filters
     * @return array<string, MetricResult>
     */
    public function fetch(Context $ctx, Period $period, array $metricIds, array $filters, Sources $sources): array
    {
        $wanted = array_values(array_intersect($metricIds, self::METRICS));
        if ($wanted === []) {
            return [];
        }

        $out = [];
        $needsValuation = array_intersect($wanted, ['inventory.stock_value', 'inventory.stock_quantity', 'inventory.item_count']) !== [];

        if ($needsValuation) {
            $valuation = $this->valuation($ctx, $period);
            $sources->record($this->product(), $this->label(), $valuation, 'The stock valuation could not be read.');
            $summary = Payload::meta($valuation)['summary'] ?? [];
            $summary = is_array($summary) ? $summary : [];

            foreach (['inventory.stock_value' => 'total_value', 'inventory.stock_quantity' => 'total_qty', 'inventory.item_count' => 'item_count'] as $metricId => $field) {
                if (!in_array($metricId, $wanted, true)) {
                    continue;
                }
                $out[$metricId] = $this->fromSummary($ctx, $period, $metricId, $valuation, $summary, $field);
            }
        }

        if (in_array('inventory.ageing_value', $wanted, true)) {
            $out['inventory.ageing_value'] = $this->ageingTotal($ctx, $period, $sources);
        }

        return $out;
    }

    /**
     * @param array{ok:bool, status:int, body:?array, error:?string} $response
     * @param array<string|int, mixed>                               $summary
     */
    private function fromSummary(Context $ctx, Period $period, string $metricId, array $response, array $summary, string $field): MetricResult
    {
        $definition = MetricCatalog::require($metricId);
        $endpoint = 'GET /api/v1/valuation';

        if (!($response['ok'] ?? false)) {
            $status = (int) ($response['status'] ?? 0);
            $result = $status === 403
                ? MetricResult::denied($definition, $ctx, $period, 'Inventory does not let this account see the valuation. The permission needed there is reports.valuation.read.')
                : MetricResult::unavailable($definition, $ctx, $period, $this->failureText($response, $definition->label));

            return $result->withProvenance($this->product(), $definition->binding, $endpoint);
        }

        $value = Payload::decimal($summary, $field);
        if ($value === null) {
            return MetricResult::unavailable(
                $definition,
                $ctx,
                $period,
                'Inventory answered without the valuation summary. The field it is read from is no longer in the reply.',
            )
                ->withProvenance($this->product(), $definition->binding, $endpoint)
                ->withWarning('Expected meta.summary.' . $field . ' in the Inventory reply and it was absent.');
        }

        $result = MetricResult::available($definition, $value, $ctx, $period)
            ->withProvenance($this->product(), $definition->binding, $endpoint)
            ->withSourceAsOf(Payload::text($summary, 'as_of') ?: $period->to)
            ->withWarning('Valuation as at ' . ($summary['as_of'] ?? $period->to) . ', ' . Payload::text($summary, 'method', 'the configured method') . ' costing.');

        // A total of units across items with different units of measure is a
        // number with no unit. Say so rather than print it bare.
        if ($metricId === 'inventory.stock_quantity') {
            $result->withWarning('Quantities are summed across items, which can mix units of measure. Filter to one item or item group for a figure with a single unit.');
        }

        return $result;
    }

    private function ageingTotal(Context $ctx, Period $period, Sources $sources): MetricResult
    {
        $definition = MetricCatalog::require('inventory.ageing_value');
        $endpoint = 'GET /api/v1/reports/stock-ageing';
        $response = $this->ageing($ctx, $period, 200);

        if (!($response['ok'] ?? false)) {
            $sources->record($this->product(), $this->label(), $response, 'The stock ageing could not be read.');
            $status = (int) ($response['status'] ?? 0);
            $result = $status === 403
                ? MetricResult::denied($definition, $ctx, $period, 'Inventory does not let this account see stock ageing.')
                : MetricResult::unavailable($definition, $ctx, $period, $this->failureText($response, $definition->label));

            return $result->withProvenance($this->product(), $definition->binding, $endpoint);
        }

        $sources->ready($this->product(), $this->label());

        $buckets = $this->ageingBuckets($response);
        $total = Decimal::sum(array_map(static fn (array $r) => $r['value'] ?? '0', $buckets));

        $result = MetricResult::available($definition, $total, $ctx, $period)
            ->withProvenance($this->product(), $definition->binding, $endpoint)
            ->withBreakdown($buckets)
            ->withSourceAsOf($period->to);

        $meta = Payload::meta($response);
        $reported = Payload::integer($meta, 'total');
        $returned = count(Payload::data($response));
        if ($reported !== null && $returned > 0 && $reported > $returned) {
            $result->withPartialCoverage(sprintf(
                'Inventory holds %d ageing rows and this reads the first %d, so the total below covers part of the stock only.',
                $reported,
                $returned,
            ));
        }

        return $result;
    }

    /**
     * @param array{ok:bool, status:int, body:?array, error:?string} $response
     * @return list<array{key:string, label:string, value:?string, link:?array<string,mixed>}>
     */
    private function ageingBuckets(array $response): array
    {
        // Inventory reports an age bucket per row. Rolling them up here keeps
        // the bucket names the source chose rather than inventing our own,
        // which would drift from its report the first time it added one.
        $totals = [];
        foreach (Payload::data($response) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bucket = Payload::text($row, 'age_bucket')
                ?: Payload::text($row, 'bucket')
                ?: Payload::text($row, 'ageing_bucket', 'unclassified');
            $value = Payload::firstDecimal($row, ['value', 'stock_value', 'amount', 'closing_value']);
            if ($value === null) {
                continue;
            }
            $totals[$bucket] = Decimal::add($totals[$bucket] ?? '0', $value);
        }

        $rows = [];
        foreach ($totals as $bucket => $value) {
            $rows[] = [
                'key'   => (string) $bucket,
                'label' => Payload::label($bucket, 'Unclassified'),
                'value' => $value,
                'link'  => null,
            ];
        }

        usort($rows, static fn (array $a, array $b) => strcmp($a['key'], $b['key']));

        return $rows;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function series(Context $ctx, Period $period, string $metricId, array $filters, Sources $sources): ?MetricResult
    {
        // Inventory answers "what is the stock worth now", not "what was it
        // worth each month". Producing a trend would mean one valuation replay
        // per bucket, which is an expensive call, and faking one by dividing a
        // closing balance would be a fabrication. Neither is acceptable, so
        // there is no series here.
        return null;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function breakdown(Context $ctx, Period $period, string $metricId, string $dimension, array $filters, Sources $sources): ?MetricResult
    {
        if ($metricId === 'inventory.ageing_value' && $dimension === 'ageing_bucket') {
            return $this->ageingTotal($ctx, $period, $sources);
        }

        if ($metricId !== 'inventory.stock_value' || !in_array($dimension, ['item', 'warehouse', 'item_group'], true)) {
            return null;
        }

        $definition = MetricCatalog::require($metricId);
        $endpoint = 'GET /api/v1/valuation';
        $limit = 20;
        $response = $this->valuation($ctx, $period, $limit, 'value', 'desc');

        if (!($response['ok'] ?? false)) {
            $sources->record($this->product(), $this->label(), $response, 'The valuation breakdown could not be read.');

            return MetricResult::unavailable($definition, $ctx, $period, $this->failureText($response, $definition->label))
                ->withProvenance($this->product(), $definition->binding, $endpoint);
        }

        $sources->ready($this->product(), $this->label());

        $keyField = match ($dimension) {
            'warehouse'  => 'warehouse_id',
            'item_group' => 'item_group_id',
            default      => 'item_id',
        };
        $labelField = match ($dimension) {
            'warehouse'  => 'warehouse_name',
            'item_group' => 'item_group_name',
            default      => 'item_name',
        };

        $totals = [];
        $labels = [];
        foreach (Payload::data($response) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) (Payload::integer($row, $keyField) ?? Payload::text($row, $keyField, ''));
            if ($key === '') {
                continue;
            }
            $value = Payload::firstDecimal($row, ['value', 'closing_value', 'stock_value', 'total_value']);
            if ($value === null) {
                continue;
            }
            $totals[$key] = Decimal::add($totals[$key] ?? '0', $value);
            $labels[$key] ??= Payload::label($row[$labelField] ?? null, $dimension . ' #' . $key);
        }

        arsort($totals, SORT_NATURAL);

        $rows = [];
        foreach ($totals as $key => $value) {
            $rows[] = [
                'key'   => $key,
                'label' => $labels[$key],
                'value' => $value,
                'link'  => $dimension === 'item'
                    ? ['product' => 'inventory', 'route' => '/items/' . $key, 'params' => $ctx->asQuery(), 'label' => 'Open the item in Inventory']
                    : null,
            ];
        }

        $result = MetricResult::available($definition, Decimal::sum(array_values($totals)), $ctx, $period)
            ->withProvenance($this->product(), $definition->binding, $endpoint)
            ->withBreakdown($rows)
            ->withSourceAsOf($period->to);

        $reported = Payload::integer(Payload::meta($response), 'total');
        if ($reported !== null && $reported > count(Payload::data($response))) {
            $result->withPartialCoverage(sprintf(
                'Inventory holds %d valued rows and this reads the highest %d by value, so the total below is the top of the list rather than the whole stock.',
                $reported,
                $limit,
            ));
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function status(Context $ctx): array
    {
        $client = $this->client();
        $probe = $client->accessMe($ctx);

        $configured = $client->isConfigured();
        $reachable = ($probe['ok'] ?? false) || (int) ($probe['status'] ?? 0) >= 400;
        $permitted = ($probe['ok'] ?? false) && (int) ($probe['status'] ?? 0) !== 403;

        return [
            'product'    => $this->product(),
            'label'      => $this->label(),
            'configured' => $configured,
            'reachable'  => $reachable,
            'permitted'  => $permitted,
            'status'     => match (true) {
                !$configured => Sources::NOT_CONFIGURED,
                !$reachable  => Sources::UNAVAILABLE,
                !$permitted  => Sources::UNAVAILABLE,
                default      => Sources::READY,
            },
            'message'    => match (true) {
                !$configured => 'No Inventory endpoint is configured for this deployment.',
                !$reachable  => 'Inventory could not be reached from this server.',
                !$permitted  => 'Your Inventory account cannot open this company\'s reports. Ask an Inventory administrator for the reports.valuation.read permission.',
                default      => null,
            },
            'metrics'    => self::METRICS,
            'dimensions' => ['item', 'item_group', 'warehouse', 'ageing_bucket'],
            'checked_at' => gmdate('c'),
            'base'       => $configured ? $client->apiRoot() : null,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function drilldown(Context $ctx, Period $period, string $metricId, array $filters): ?array
    {
        $definition = MetricCatalog::get($metricId);
        if ($definition?->drilldown === null) {
            return null;
        }

        $params = ($definition->drilldown['params'] ?? []) + $ctx->asQuery() + ['as_of' => $period->to];
        foreach (['item_id', 'warehouse_id', 'item_group_id'] as $allowed) {
            if (isset($filters[$allowed]) && is_numeric($filters[$allowed])) {
                $params[$allowed] = (int) $filters[$allowed];
            }
        }

        return [
            'product' => $definition->drilldown['product'],
            'route'   => $definition->drilldown['route'],
            'params'  => $params,
            'label'   => 'Open in ' . self::LABEL,
        ];
    }

    /** @return array{ok:bool, status:int, body:?array, error:?string} */
    private function valuation(Context $ctx, Period $period, int $limit = 1, string $sort = 'item_name', string $order = 'asc'): array
    {
        $key = 'valuation|' . $ctx->key() . '|' . $period->to . '|' . $limit . '|' . $sort . '|' . $order;

        return $this->memo[$key] ??= $this->client()->valuation($ctx, $period->to, 'weighted_average', $limit, $sort, $order);
    }

    /** @return array{ok:bool, status:int, body:?array, error:?string} */
    private function ageing(Context $ctx, Period $period, int $limit): array
    {
        $key = 'ageing|' . $ctx->key() . '|' . $period->to . '|' . $limit;

        return $this->memo[$key] ??= $this->client()->stockAgeing($ctx, $period->to, $limit);
    }

    /** @param array{ok:bool, status:int, error?:?string} $response */
    private function failureText(array $response, string $label): string
    {
        return match ((int) ($response['status'] ?? 0)) {
            0       => 'Inventory could not be reached, so ' . strtolower($label) . ' is unavailable rather than zero.',
            401     => 'Inventory did not accept this session. Sign in again.',
            403     => 'Inventory does not let this account see ' . strtolower($label) . '.',
            404     => 'Inventory does not offer the report ' . strtolower($label) . ' is read from in this deployment, so it is unavailable rather than zero.',
            429     => 'Inventory is rate limiting this deployment, so this is unavailable rather than zero. Try again shortly.',
            default => 'Inventory could not answer for ' . strtolower($label) . ' right now, so it is unavailable rather than zero.',
        };
    }
}
