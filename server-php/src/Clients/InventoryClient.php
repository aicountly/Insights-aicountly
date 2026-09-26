<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Context;

/**
 * Read-only reads against inventory.aicountly.com.
 *
 * INVENTORY IS THE AUTHORITY for quantity on hand, inventory cost, valuation
 * and stock ageing. Books carries the stock FIGURE that appears on a balance
 * sheet; Inventory carries the stock itself, item by item and warehouse by
 * warehouse. When a screen shows both, Insights says which one it asked.
 *
 * VERIFIED CONTRACT (Inventory-aicountly/server-php/app/Config/Routes.php,
 * all under the `v1` group, all requiring cmp_id/fy_id/bo_id and a Bearer
 * ses_key):
 *   GET v1/valuation?as_of&method    ValuationController::snapshot,
 *                                    `reports.valuation.read`
 *                                    -> data[] rows, meta.summary{as_of,
 *                                       method, total_qty, total_value,
 *                                       item_count}
 *   GET v1/reports/stock-ageing?as_of      ReportsController::stockAgeing
 *   GET v1/reports/stock-summary           ReportsController::stockSummary
 *   GET v1/reports/movement-analysis       fast / slow / non-moving / dead
 *   GET v1/reports/near-expiry?days        batches expiring soon
 *   GET v1/dashboard                       DashboardController::index, `dashboard.read`
 *   GET v1/items, v1/warehouses, v1/item-groups   masters, for pickers
 *   GET v1/access/me                       what this caller may do in Inventory
 */
final class InventoryClient extends ApiClient
{
    public const LABEL = 'Inventory';

    private string $authorization = '';

    public function service(): string
    {
        return 'inventory';
    }

    protected function productionBase(): string
    {
        return 'https://inventory.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://inventory.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'INVENTORY_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $this->authorization = 'Bearer ' . $sesKey;

        return $this;
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        return ['Authorization' => $this->authorization];
    }

    private function read(string $path): array
    {
        return $this->request('GET', $path, null, $this->authHeaders());
    }

    /**
     * The valuation snapshot.
     *
     * `limit` is deliberately small here: Insights wants the SUMMARY — the
     * total value and the item count — not forty thousand item rows. The
     * summary arrives in `meta.summary` whatever the page size, so asking for
     * one row and reading the meta is a complete answer that costs one row on
     * the wire. A ranking widget asks for its own page with a sort.
     */
    public function valuation(Context $ctx, string $asOf, string $method = 'weighted_average', int $limit = 1, string $sort = 'item_name', string $order = 'asc'): array
    {
        return $this->read('v1/valuation' . self::query([
            'as_of'  => $asOf,
            'method' => $method,
            'limit'  => $limit,
            'sort'   => $sort,
            'order'  => $order,
        ] + $ctx->asQuery()));
    }

    public function stockAgeing(Context $ctx, string $asOf, int $limit = 100, int $offset = 0): array
    {
        return $this->read('v1/reports/stock-ageing' . self::query([
            'as_of'  => $asOf,
            'limit'  => $limit,
            'offset' => $offset,
        ] + $ctx->asQuery()));
    }

    public function movementAnalysis(Context $ctx, string $from, string $to, int $limit = 50): array
    {
        return $this->read('v1/reports/movement-analysis' . self::query([
            'from'  => $from,
            'to'    => $to,
            'limit' => $limit,
        ] + $ctx->asQuery()));
    }

    public function nearExpiry(Context $ctx, int $days = 30, int $limit = 50): array
    {
        return $this->read('v1/reports/near-expiry' . self::query([
            'days'  => $days,
            'limit' => $limit,
        ] + $ctx->asQuery()));
    }

    public function stockSummary(Context $ctx, string $from, string $to, int $limit = 50, string $sort = 'item_name', string $order = 'asc'): array
    {
        return $this->read('v1/reports/stock-summary' . self::query([
            'from'    => $from,
            'to'      => $to,
            'limit'   => $limit,
            'sort'    => $sort,
            'order'   => $order,
            'nonzero' => 1,
        ] + $ctx->asQuery()));
    }

    public function dashboard(Context $ctx): array
    {
        return $this->read('v1/dashboard' . self::query($ctx->asQuery()));
    }

    public function warehouses(Context $ctx): array
    {
        return $this->read('v1/warehouses' . self::query(['limit' => 200] + $ctx->asQuery()));
    }

    public function itemGroups(Context $ctx): array
    {
        return $this->read('v1/item-groups' . self::query(['limit' => 200] + $ctx->asQuery()));
    }

    public function accessMe(Context $ctx): array
    {
        return $this->read('v1/access/me' . self::query($ctx->asQuery()));
    }
}
