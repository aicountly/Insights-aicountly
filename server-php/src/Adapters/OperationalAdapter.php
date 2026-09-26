<?php

declare(strict_types=1);

namespace Aicountly\Api\Adapters;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\OperationalClient;
use Aicountly\Api\Context;
use Aicountly\Api\Metrics\MetricResult;
use Aicountly\Api\Support\Period;
use Aicountly\Api\Support\Sources;

/**
 * Sales, Purchases, Billing and POS — connection and capability only.
 *
 * THIS ADAPTER DELIBERATELY ANSWERS NO METRICS, and that is a design decision
 * rather than an omission. Those products each publish a rich dashboard, and
 * every money figure on it describes the SAME transactions Books has already
 * recorded: a POS ticket becomes a Billing invoice becomes a Books sales
 * voucher. Binding a metric to one of them would put the same rupee on a
 * dashboard twice — once as "POS takings" and once as "net sales" — and the
 * reader has no way to tell.
 *
 * So these products appear in Insights as SOURCES OF STATUS: the Data Sources
 * screen shows whether each is connected and what this viewer may see there,
 * and a widget can link into them. When one of them exposes an operational
 * measure Books genuinely does not hold — open orders, quotations in flight,
 * till sessions unreconciled — it belongs in the catalogue with
 * `accounting_basis: operational`, bound here, and MetricDefinition's
 * compatibility check keeps it from being added to an accounting figure.
 *
 * Nothing about that is theoretical: `status()` below is a live call. An
 * adapter that compiles is not a working integration, and this is where the
 * difference is reported.
 */
final class OperationalAdapter implements SourceAdapter
{
    private readonly OperationalClient $client;

    public function __construct(
        private readonly string $productKey,
        private readonly Auth $auth,
    ) {
        $this->client = OperationalClient::for($productKey)->withSession($auth->sesKey());
    }

    public function product(): string
    {
        return $this->productKey;
    }

    public function label(): string
    {
        return OperationalClient::labelFor($this->productKey);
    }

    /** @return list<string> */
    public function metrics(): array
    {
        return [];
    }

    /**
     * @param list<string>         $metricIds
     * @param array<string, mixed> $filters
     * @return array<string, MetricResult>
     */
    public function fetch(Context $ctx, Period $period, array $metricIds, array $filters, Sources $sources): array
    {
        return [];
    }

    /** @param array<string, mixed> $filters */
    public function series(Context $ctx, Period $period, string $metricId, array $filters, Sources $sources): ?MetricResult
    {
        return null;
    }

    /** @param array<string, mixed> $filters */
    public function breakdown(Context $ctx, Period $period, string $metricId, string $dimension, array $filters, Sources $sources): ?MetricResult
    {
        return null;
    }

    /**
     * A live check, made with the viewer's own session.
     *
     * `permissions` is the cheapest company-scoped read each of these products
     * offers, which makes it the honest probe: it proves the base URL resolves,
     * the product is up, it accepts this session, AND this person may open this
     * company there. A health endpoint would only prove the first two.
     *
     * @return array<string, mixed>
     */
    public function status(Context $ctx): array
    {
        $enabled = $this->client->isEnabled();

        if (!$enabled) {
            return [
                'product'    => $this->product(),
                'label'      => $this->label(),
                'configured' => false,
                'reachable'  => false,
                'permitted'  => false,
                'status'     => Sources::NOT_CONFIGURED,
                'message'    => $this->label() . ' is not switched on for this deployment. Add it to INSIGHTS_SOURCES in the API environment to include it.',
                'metrics'    => [],
                'dimensions' => [],
                'checked_at' => gmdate('c'),
                'base'       => null,
            ];
        }

        $probe = $this->client->permissions($ctx);
        $status = (int) ($probe['status'] ?? 0);
        $reachable = ($probe['ok'] ?? false) || $status >= 400;
        $permitted = ($probe['ok'] ?? false);

        return [
            'product'    => $this->product(),
            'label'      => $this->label(),
            'configured' => true,
            'reachable'  => $reachable,
            'permitted'  => $permitted,
            'status'     => match (true) {
                !$reachable => Sources::UNAVAILABLE,
                !$permitted => Sources::UNAVAILABLE,
                default     => Sources::READY,
            },
            'message'    => match (true) {
                !$reachable    => $this->label() . ' could not be reached from this server.',
                $status === 403 => 'Your ' . $this->label() . ' account cannot open this company.',
                $status === 401 => $this->label() . ' did not accept this session.',
                !$permitted    => $this->label() . ' could not confirm your access.',
                // Connected and usable, and honest about what it contributes.
                default        => 'Connected. ' . $this->label() . ' contributes links and context; its money figures are reported by Smart Books so the same sale is not counted twice.',
            },
            'metrics'    => [],
            'dimensions' => [],
            'checked_at' => gmdate('c'),
            'base'       => $this->client->apiRoot(),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function drilldown(Context $ctx, Period $period, string $metricId, array $filters): ?array
    {
        return null;
    }
}
