<?php

declare(strict_types=1);

namespace Aicountly\Api\Adapters;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Context;
use Aicountly\Api\Metrics\MetricCatalog;
use Aicountly\Api\Metrics\MetricResult;
use Aicountly\Api\Support\Decimal;
use Aicountly\Api\Support\Payload;
use Aicountly\Api\Support\Period;
use Aicountly\Api\Support\Sources;

/**
 * Smart Books, adapted once.
 *
 * Books answers most of what a business dashboard asks, and it answers it
 * pre-aggregated: one call to `dashboard/sales` carries seven KPIs, a daily
 * trend, the top customers and the receivables ageing. So this adapter is built
 * around FOUR BUNDLE CALLS rather than one call per metric — twenty KPI widgets
 * on a board cost the same four requests as one.
 *
 * Every figure is converted to an exact decimal STRING on the way in. Books
 * sends JSON numbers, json_decode makes those floats, and a float is where
 * paise go missing. The conversion happens once, here.
 *
 * A PERIOD METRIC AND A BALANCE ARE READ DIFFERENTLY. `dashboard/sales?from&to`
 * sums flows over the window; `kpis.receivables` in the same payload is the
 * balance AS AT `to`. The catalogue marks which is which, and the comparison
 * pass re-reads the comparison window rather than assuming the previous
 * balance can be derived from the current one.
 */
final class BooksAdapter implements SourceAdapter
{
    public const LABEL = BooksClient::LABEL;

    /**
     * Which bundle call answers which metric, and at what path inside it.
     *
     * @var array<string, array{call:string, path:string}>
     */
    private const BINDINGS = [
        'finance.net_revenue'         => ['call' => 'sales', 'path' => 'kpis.total_sales'],
        'finance.taxable_revenue'     => ['call' => 'sales', 'path' => 'kpis.taxable_sales'],
        'finance.gst_collected'       => ['call' => 'sales', 'path' => 'kpis.gst_collected'],
        'finance.invoice_count'       => ['call' => 'sales', 'path' => 'kpis.total_invoices'],
        'finance.avg_invoice_value'   => ['call' => 'sales', 'path' => 'kpis.avg_invoice_value'],
        'finance.receivables'         => ['call' => 'sales', 'path' => 'kpis.receivables'],
        'finance.overdue_receivables' => ['call' => 'sales', 'path' => 'kpis.overdue_receivables'],
        'finance.sales_returns'       => ['call' => 'sales', 'path' => 'register_summary.sales_returns_amount'],

        'finance.purchase_spend'      => ['call' => 'purchase', 'path' => 'kpis.total_purchases'],
        'finance.taxable_purchases'   => ['call' => 'purchase', 'path' => 'kpis.taxable_purchases'],
        'finance.input_gst'           => ['call' => 'purchase', 'path' => 'kpis.input_gst'],
        'finance.payables'            => ['call' => 'purchase', 'path' => 'kpis.payables'],
        'finance.overdue_payables'    => ['call' => 'purchase', 'path' => 'kpis.overdue_payables'],

        'finance.cash_and_bank'       => ['call' => 'default', 'path' => 'kpis.cash_and_bank'],
        'finance.gst_payable'         => ['call' => 'default', 'path' => 'kpis.gst_payable'],
        'finance.income'              => ['call' => 'default', 'path' => 'kpis.income'],
        'finance.expense'             => ['call' => 'default', 'path' => 'kpis.expense'],
        'finance.net_profit'          => ['call' => 'default', 'path' => 'kpis.net_profit'],
        'finance.stock_value'         => ['call' => 'default', 'path' => 'kpis.stock_value'],

        'finance.collections'         => ['call' => 'collections', 'path' => 'kpis.collections'],
        'finance.payments_made'       => ['call' => 'collections', 'path' => 'kpis.payments_made'],
    ];

    /** The endpoint each bundle call maps to, for provenance. */
    private const ENDPOINTS = [
        'sales'       => 'GET /api/dashboard/sales',
        'purchase'    => 'GET /api/dashboard/purchase',
        'default'     => 'GET /api/dashboard/default',
        'collections' => 'GET /api/dashboard/collections',
    ];

    /** @var array<string, array{ok:bool, status:int, body:?array, error:?string}> */
    private array $bundleMemo = [];

    public function __construct(private readonly Auth $auth)
    {
    }

    public function product(): string
    {
        return 'books';
    }

    public function label(): string
    {
        return self::LABEL;
    }

    /** @return list<string> */
    public function metrics(): array
    {
        return array_keys(self::BINDINGS);
    }

    private function client(): BooksClient
    {
        return (new BooksClient())->withSession($this->auth->sesKey());
    }

    /**
     * @param list<string>         $metricIds
     * @param array<string, mixed> $filters
     * @return array<string, MetricResult>
     */
    public function fetch(Context $ctx, Period $period, array $metricIds, array $filters, Sources $sources): array
    {
        $wanted = array_values(array_intersect($metricIds, $this->metrics()));
        if ($wanted === []) {
            return [];
        }

        $calls = array_values(array_unique(array_map(
            static fn (string $id) => self::BINDINGS[$id]['call'],
            $wanted,
        )));

        $responses = $this->bundle($ctx, $period, $calls);
        $anyOk = false;
        $firstError = null;

        foreach ($responses as $response) {
            if ($response['ok'] ?? false) {
                $anyOk = true;
            } elseif ($firstError === null) {
                $firstError = $response;
            }
        }

        if ($anyOk) {
            $sources->ready($this->product(), $this->label());
        } else {
            $sources->record($this->product(), $this->label(), $firstError ?? ['ok' => false, 'status' => 0], 'Smart Books did not answer.');
        }

        $out = [];
        foreach ($wanted as $metricId) {
            $out[$metricId] = $this->readOne($ctx, $period, $metricId, $responses);
        }

        return $out;
    }

    /**
     * @param array<string, array{ok:bool, status:int, body:?array, error:?string}> $responses
     */
    private function readOne(Context $ctx, Period $period, string $metricId, array $responses): MetricResult
    {
        $definition = MetricCatalog::require($metricId);
        $binding = self::BINDINGS[$metricId];
        $response = $responses[$binding['call']] ?? ['ok' => false, 'status' => 0, 'body' => null, 'error' => null];

        if (!($response['ok'] ?? false)) {
            $status = (int) ($response['status'] ?? 0);

            $result = $status === 403
                ? MetricResult::denied($definition, $ctx, $period, 'Smart Books does not let this account see ' . strtolower($definition->label) . '. The permission needed there is ' . implode(' or ', $definition->sourcePermissions) . '.')
                : MetricResult::unavailable($definition, $ctx, $period, $this->failureText($response, $definition->label));

            return $result->withProvenance($this->product(), $definition->binding, self::ENDPOINTS[$binding['call']]);
        }

        $data = Payload::data($response);
        $value = Payload::decimal($data, $binding['path']);

        if ($value === null) {
            // The call succeeded and the field was not in it. That is a contract
            // change, not a zero, and saying so is what stops it being silent.
            return MetricResult::unavailable(
                $definition,
                $ctx,
                $period,
                'Smart Books answered without ' . strtolower($definition->label) . '. The field it is read from is no longer in the reply.',
            )
                ->withProvenance($this->product(), $definition->binding, self::ENDPOINTS[$binding['call']])
                ->withWarning('Expected ' . $binding['path'] . ' in the Smart Books reply and it was absent.');
        }

        $result = MetricResult::available($definition, $value, $ctx, $period)
            ->withProvenance($this->product(), $definition->binding, self::ENDPOINTS[$binding['call']]);

        // A balance is stated as at a date; saying which one is the difference
        // between a number and a number somebody can check.
        if ($definition->isBalance) {
            $result->withSourceAsOf($period->to)
                ->withWarning('Balance as at ' . $period->to . '.');
        }

        return $result;
    }

    /**
     * The comparison-window value for a set of metrics.
     *
     * A SECOND READ, not a derivation. Books' own `prev_period_kpis` covers the
     * window Books chose, which is the equally sized one before — correct when
     * the caller asked for `previous_period` and wrong when they asked for
     * `previous_year`. So the comparison window is fetched explicitly whenever
     * it is not the one Books would have picked.
     *
     * @param list<string>         $metricIds
     * @param array<string, mixed> $filters
     * @return array<string, ?string>
     */
    public function comparisonValues(Context $ctx, Period $period, array $metricIds, array $filters): array
    {
        $comparison = $period->comparison();
        if ($comparison === null) {
            return [];
        }

        $wanted = array_values(array_intersect($metricIds, $this->metrics()));
        if ($wanted === []) {
            return [];
        }

        $calls = array_values(array_unique(array_map(
            static fn (string $id) => self::BINDINGS[$id]['call'],
            $wanted,
        )));

        $responses = $this->bundle($ctx, $comparison, $calls);

        $out = [];
        foreach ($wanted as $metricId) {
            $binding = self::BINDINGS[$metricId];
            $response = $responses[$binding['call']] ?? null;
            $out[$metricId] = ($response !== null && ($response['ok'] ?? false))
                ? Payload::decimal(Payload::data($response), $binding['path'])
                : null;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function series(Context $ctx, Period $period, string $metricId, array $filters, Sources $sources): ?MetricResult
    {
        // Books publishes a DAILY trend on the sales, purchase and collections
        // dashboards and nowhere else. A balance has no trend there, and
        // manufacturing one by spreading the closing figure over the period
        // would be an invention.
        $trendCall = match ($metricId) {
            'finance.net_revenue'   => 'sales',
            'finance.purchase_spend' => 'purchase',
            'finance.collections', 'finance.payments_made' => 'collections',
            default => null,
        };

        if ($trendCall === null) {
            return null;
        }

        $definition = MetricCatalog::require($metricId);
        $responses = $this->bundle($ctx, $period, [$trendCall]);
        $response = $responses[$trendCall];

        if (!($response['ok'] ?? false)) {
            $sources->record($this->product(), $this->label(), $response, 'The ' . strtolower($definition->label) . ' trend could not be read.');

            return MetricResult::unavailable($definition, $ctx, $period, $this->failureText($response, $definition->label))
                ->withProvenance($this->product(), $definition->binding, self::ENDPOINTS[$trendCall]);
        }

        $sources->ready($this->product(), $this->label());
        $data = Payload::data($response);

        // Books answers at day grain. Anything coarser is rolled up here by
        // the calendar, so a "month" bucket is a month and not thirty days.
        $daily = [];
        foreach (Payload::rows($data, 'trend.points') as $point) {
            $date = Payload::text($point, 'date') ?: Payload::text($point, 'd');
            if ($date === '') {
                continue;
            }
            $amount = Payload::firstDecimal($point, [
                $metricId === 'finance.payments_made' ? 'payments' : 'collections',
                'value',
                'amt',
                'amount',
            ]);
            if ($amount === null) {
                continue;
            }
            $daily[substr($date, 0, 10)] = Decimal::add($daily[substr($date, 0, 10)] ?? '0', $amount);
        }

        $points = [];
        $total = '0';
        foreach ($period->buckets() as $bucket) {
            $bucketTotal = '0';
            foreach ($daily as $date => $amount) {
                if ($date >= $bucket['from'] && $date <= $bucket['to']) {
                    $bucketTotal = Decimal::add($bucketTotal, $amount);
                }
            }
            $total = Decimal::add($total, $bucketTotal);
            $points[] = [
                'key'     => $bucket['key'],
                'label'   => $bucket['label'],
                'value'   => $bucketTotal,
                'partial' => $bucket['partial'],
            ];
        }

        $result = MetricResult::available($definition, $total, $ctx, $period)
            ->withProvenance($this->product(), $definition->binding, self::ENDPOINTS[$trendCall])
            ->withSeries($points);

        foreach ($points as $point) {
            if ($point['partial']) {
                $result->withWarning('The period cuts across a ' . $period->grain . ', so at least one bucket covers part of it only.');
                break;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function breakdown(Context $ctx, Period $period, string $metricId, string $dimension, array $filters, Sources $sources): ?MetricResult
    {
        /** @var array<string, array{call:string, path:string, key:string, label:string, value:list<string>}>|null $shape */
        $shape = match (true) {
            $metricId === 'finance.net_revenue' && $dimension === 'customer' => [
                'call' => 'sales', 'path' => 'top_customers', 'key' => 'acc_id', 'label' => 'label', 'value' => ['amount', 'value'],
            ],
            $metricId === 'finance.net_revenue' && $dimension === 'item' => [
                'call' => 'sales', 'path' => 'top_items', 'key' => 'item_id', 'label' => 'label', 'value' => ['amount', 'value'],
            ],
            $metricId === 'finance.purchase_spend' && $dimension === 'supplier' => [
                'call' => 'purchase', 'path' => 'top_suppliers', 'key' => 'acc_id', 'label' => 'label', 'value' => ['amount', 'value'],
            ],
            $metricId === 'finance.purchase_spend' && $dimension === 'item' => [
                'call' => 'purchase', 'path' => 'top_items', 'key' => 'item_id', 'label' => 'label', 'value' => ['amount', 'value'],
            ],
            $metricId === 'finance.expense' && $dimension === 'expense_head' => [
                'call' => 'default', 'path' => 'top_expenses', 'key' => 'acc_id', 'label' => 'acc_name', 'value' => ['amount', 'total', 'value'],
            ],
            $metricId === 'finance.collections' && $dimension === 'customer' => [
                'call' => 'collections', 'path' => 'top_collections', 'key' => 'acc_id', 'label' => 'label', 'value' => ['amount'],
            ],
            $metricId === 'finance.payments_made' && $dimension === 'supplier' => [
                'call' => 'collections', 'path' => 'top_payments', 'key' => 'acc_id', 'label' => 'label', 'value' => ['amount'],
            ],
            in_array($metricId, ['finance.receivables', 'finance.overdue_receivables'], true) && $dimension === 'ageing_bucket' => [
                'call' => 'sales', 'path' => 'receivables_ageing', 'key' => '', 'label' => '', 'value' => [],
            ],
            in_array($metricId, ['finance.payables', 'finance.overdue_payables'], true) && $dimension === 'ageing_bucket' => [
                'call' => 'purchase', 'path' => 'payables_ageing', 'key' => '', 'label' => '', 'value' => [],
            ],
            default => null,
        };

        if ($shape === null) {
            return null;
        }

        $definition = MetricCatalog::require($metricId);
        $responses = $this->bundle($ctx, $period, [$shape['call']]);
        $response = $responses[$shape['call']];

        if (!($response['ok'] ?? false)) {
            $sources->record($this->product(), $this->label(), $response, 'The breakdown could not be read.');

            return MetricResult::unavailable($definition, $ctx, $period, $this->failureText($response, $definition->label))
                ->withProvenance($this->product(), $definition->binding, self::ENDPOINTS[$shape['call']]);
        }

        $sources->ready($this->product(), $this->label());
        $data = Payload::data($response);

        $rows = $shape['key'] === ''
            ? $this->ageingRows($data, $shape['path'])
            : $this->rankedRows($data, $shape, $metricId, $ctx);

        $total = Decimal::sum(array_map(static fn (array $r) => $r['value'] ?? '0', $rows));

        $result = MetricResult::available($definition, $total, $ctx, $period)
            ->withProvenance($this->product(), $definition->binding, self::ENDPOINTS[$shape['call']])
            ->withBreakdown($rows);

        // A "top 5" is not the whole population, and a pie chart drawn from one
        // as though it were says the business has five customers.
        if ($shape['key'] !== '') {
            $result->withPartialCoverage(
                'Smart Books returns the leading ' . count($rows) . ' only, so this shows the top entries rather than every one.',
            );
        }

        return $result;
    }

    /**
     * @param array<string|int, mixed> $data
     * @return list<array{key:string, label:string, value:?string, link:?array<string,mixed>}>
     */
    private function ageingRows(array $data, string $path): array
    {
        $labels = [
            'not_due'   => 'Not yet due',
            'b_0_30'    => '1–30 days',
            'b_31_60'   => '31–60 days',
            'b_61_90'   => '61–90 days',
            'b_90_plus' => 'Over 90 days',
        ];

        $bucket = Payload::path($data, $path);
        if (!is_array($bucket)) {
            return [];
        }

        $rows = [];
        foreach ($labels as $key => $label) {
            // `total` is deliberately skipped: it is the sum of the others, and
            // charting it beside them draws the same money twice.
            $value = Payload::decimal($bucket, $key);
            $rows[] = ['key' => $key, 'label' => $label, 'value' => $value, 'link' => null];
        }

        return $rows;
    }

    /**
     * @param array<string|int, mixed> $data
     * @param array{call:string, path:string, key:string, label:string, value:list<string>} $shape
     * @return list<array{key:string, label:string, value:?string, link:?array<string,mixed>}>
     */
    private function rankedRows(array $data, array $shape, string $metricId, Context $ctx): array
    {
        $rows = [];
        foreach (Payload::rows($data, $shape['path']) as $row) {
            $id = Payload::integer($row, $shape['key']);
            $value = Payload::firstDecimal($row, $shape['value']);
            if ($value === null) {
                continue;
            }
            $rows[] = [
                'key'   => (string) ($id ?? count($rows)),
                // A party name is another product's user input. It is a label,
                // never markup and never an instruction.
                'label' => Payload::label($row[$shape['label']] ?? null),
                'value' => $value,
                'link'  => $id === null ? null : $this->rowLink($metricId, $shape['key'], $id, $ctx),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function rowLink(string $metricId, string $keyField, int $id, Context $ctx): ?array
    {
        if ($keyField === 'acc_id') {
            return [
                'product' => 'books',
                'route'   => '/reports/bill-by-bill',
                'params'  => ['acc_id' => $id] + $ctx->asQuery(),
                'label'   => 'Open the ledger in Smart Books',
            ];
        }
        if ($keyField === 'item_id') {
            return [
                'product' => 'inventory',
                'route'   => '/items/' . $id,
                'params'  => $ctx->asQuery(),
                'label'   => 'Open the item in Inventory',
            ];
        }

        return null;
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
                !$configured => 'No Smart Books endpoint is configured for this deployment.',
                !$reachable  => 'Smart Books could not be reached from this server.',
                !$permitted  => 'Your Smart Books account cannot open this company\'s reports. Ask a Smart Books administrator for the dashboard.read permission.',
                default      => null,
            },
            'metrics'    => $this->metrics(),
            'dimensions' => ['time', 'branch', 'customer', 'supplier', 'item', 'ageing_bucket', 'expense_head'],
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

        $target = $definition->drilldown;
        $params = ($target['params'] ?? []) + $ctx->asQuery() + ['from' => $period->from, 'to' => $period->to];

        // Only the parameters this product decided on. A filter the caller sent
        // is applied only when it names a field the destination understands.
        foreach (['acc_id', 'item_id', 'warehouse_id'] as $allowed) {
            if (isset($filters[$allowed]) && is_numeric($filters[$allowed])) {
                $params[$allowed] = (int) $filters[$allowed];
            }
        }

        return [
            'product' => $target['product'],
            'route'   => $target['route'],
            'params'  => $params,
            'label'   => 'Open in ' . self::LABEL,
        ];
    }

    /**
     * The four bundle calls, fetched at most once each per request.
     *
     * @param list<string> $calls
     * @return array<string, array{ok:bool, status:int, body:?array, error:?string}>
     */
    private function bundle(Context $ctx, Period $period, array $calls): array
    {
        $memoKey = $ctx->key() . '|' . $period->from . '|' . $period->to;
        $client = $this->client();
        $out = [];
        $missing = [];

        foreach ($calls as $call) {
            $key = $memoKey . '|' . $call;
            if (isset($this->bundleMemo[$key])) {
                $out[$call] = $this->bundleMemo[$key];
                continue;
            }
            $missing[] = $call;
        }

        if ($missing !== []) {
            $fetched = count($missing) === 1
                ? [$missing[0] => $this->single($client, $ctx, $period, $missing[0])]
                : $this->multi($client, $ctx, $period, $missing);

            foreach ($fetched as $call => $response) {
                $this->bundleMemo[$memoKey . '|' . $call] = $response;
                $out[$call] = $response;
            }
        }

        return $out;
    }

    /** @return array{ok:bool, status:int, body:?array, error:?string} */
    private function single(BooksClient $client, Context $ctx, Period $period, string $call): array
    {
        return match ($call) {
            'sales'       => $client->salesSummary($ctx, $period->from, $period->to),
            'purchase'    => $client->purchaseSummary($ctx, $period->from, $period->to),
            'default'     => $client->defaultSummary($ctx, $period->from, $period->to),
            'collections' => $client->collectionsSummary($ctx, $period->from, $period->to),
            default       => ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'unknown_call'],
        };
    }

    /**
     * @param list<string> $calls
     * @return array<string, array{ok:bool, status:int, body:?array, error:?string}>
     */
    private function multi(BooksClient $client, Context $ctx, Period $period, array $calls): array
    {
        $out = [];
        foreach ($client->bundle($ctx, $period->from, $period->to, $calls) as $call => $response) {
            $out[$call] = $response;
        }

        return $out;
    }

    /** @param array{ok:bool, status:int, error?:?string} $response */
    private function failureText(array $response, string $label): string
    {
        return match ((int) ($response['status'] ?? 0)) {
            0       => 'Smart Books could not be reached, so ' . strtolower($label) . ' is unavailable rather than zero.',
            401     => 'Smart Books did not accept this session. Sign in again.',
            403     => 'Smart Books does not let this account see ' . strtolower($label) . '.',
            404     => 'Smart Books does not offer the report ' . strtolower($label) . ' is read from in this deployment, so it is unavailable rather than zero.',
            429     => 'Smart Books is rate limiting this deployment, so this is unavailable rather than zero. Try again shortly.',
            default => 'Smart Books could not answer for ' . strtolower($label) . ' right now, so it is unavailable rather than zero.',
        };
    }
}
