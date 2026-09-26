<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Context;

/**
 * Read-only reads against books.aicountly.com.
 *
 * BOOKS IS THE ACCOUNTING AUTHORITY. Revenue, GST, the receivable, the payable,
 * cash and bank, COGS and the stock value that appears on a balance sheet are
 * Books' answers and nobody else's. Insights asks; it never recomputes them
 * from a sales order or a POS ticket, because that is precisely how the same
 * sale gets counted twice.
 *
 * THIS CLIENT HAS NO WRITE METHODS AND MUST NOT ACQUIRE ANY. Insights reads.
 * If a user wants to act on something they have seen here, the answer is a link
 * into Books' own screen, where Books' own approval and audit apply.
 *
 * VERIFIED CONTRACT (books-react-app/server-php/app/Config/Routes.php):
 *   GET dashboard/default    DashboardController::defaultSummary, `dashboard.read`
 *                            -> data.kpis{income, expense, net_profit, assets,
 *                               liabilities, equity, cash_and_bank, receivables,
 *                               payables, stock_value, gst_payable},
 *                               data.prev_month_kpis, data.top_expenses
 *   GET dashboard/sales      DashboardController::salesSummary, `dashboard.read`
 *                            -> data.kpis{total_sales, taxable_sales,
 *                               gst_collected, total_invoices,
 *                               avg_invoice_value, receivables,
 *                               overdue_receivables}, data.prev_period_kpis,
 *                               data.trend.points, data.top_customers,
 *                               data.top_items, data.receivables_ageing
 *                               {not_due, b_0_30, b_31_60, b_61_90, b_90_plus,
 *                               total}, data.register_summary
 *   GET dashboard/purchase   DashboardController::purchaseSummary, `dashboard.read`
 *                            -> data.kpis{total_purchases, taxable_purchases,
 *                               input_gst, total_bills, payables,
 *                               overdue_payables}, data.payables_ageing,
 *                               data.top_suppliers, data.trend.points
 *   GET dashboard/inventory  DashboardController::inventorySummary, `dashboard.read`
 *   GET reports/profit-loss  ReportsController::profitLoss
 *   GET reports/balance-sheet
 *   GET reports/trial-balance
 *   GET reports/cash-flow?method=direct&detail=condensed
 *   GET reports/bill-by-bill?acc_id=N   REQUIRES acc_id and answers 400 without
 *                            one. It is a PER-PARTY call, never a company-wide
 *                            receivables read — the company-wide ageing comes
 *                            from the dashboard endpoints above.
 *   GET masters/accounts     the ledger, for party pickers and drill-downs
 *   GET access/me            what this caller may do in Books
 *
 * Every scoped call carries cmp_id, fy_id and bo_id, and the caller's own
 * Bearer ses_key — so Books applies that person's permissions, not ours.
 */
final class BooksClient extends ApiClient
{
    public const LABEL = 'Smart Books';

    /** Voucher types, as `books_voucher_types.vch_type_id`. Stable platform ids. */
    public const VCH_SALES       = 18;
    public const VCH_PURCHASE    = 11;
    public const VCH_CREDIT_NOTE = 2;
    public const VCH_DEBIT_NOTE  = 3;
    public const VCH_RECEIPT     = 13;
    public const VCH_PAYMENT     = 9;

    private string $authorization = '';

    public function service(): string
    {
        return 'books';
    }

    protected function productionBase(): string
    {
        return 'https://books.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://books.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'BOOKS_API_BASE';
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

    /** @return array{ok:bool, status:int, body:?array, error:?string} */
    private function read(string $path): array
    {
        return $this->request('GET', $path, null, $this->authHeaders());
    }

    // -----------------------------------------------------------------------
    // Dashboards — pre-aggregated by Books, which is why they are the binding
    // for company-wide metrics rather than a page of the voucher register.
    // -----------------------------------------------------------------------

    public function defaultSummary(Context $ctx, string $from, string $to): array
    {
        return $this->read('dashboard/default' . self::query(['from' => $from, 'to' => $to] + $ctx->asQuery()));
    }

    public function salesSummary(Context $ctx, string $from, string $to): array
    {
        return $this->read('dashboard/sales' . self::query(['from' => $from, 'to' => $to] + $ctx->asQuery()));
    }

    public function purchaseSummary(Context $ctx, string $from, string $to): array
    {
        return $this->read('dashboard/purchase' . self::query(['from' => $from, 'to' => $to] + $ctx->asQuery()));
    }

    public function inventorySummary(Context $ctx, string $from, string $to): array
    {
        return $this->read('dashboard/inventory' . self::query(['from' => $from, 'to' => $to] + $ctx->asQuery()));
    }

    /**
     * What was COLLECTED in the period, as opposed to what was invoiced.
     *
     * Added to Books for Insights (books-react-app
     * app/Services/CollectionsDashboardService.php, route dashboard/collections).
     * A deployment of Books that predates it answers 404, which this product
     * reports as "collections are unavailable on this Smart Books" rather than
     * as zero collections.
     */
    public function collectionsSummary(Context $ctx, string $from, string $to): array
    {
        return $this->read('dashboard/collections' . self::query(['from' => $from, 'to' => $to] + $ctx->asQuery()));
    }

    /**
     * Several dashboard reads in one round of parallel calls.
     *
     * Sequential, these are four optional-budget timeouts back to back. Through
     * one curl_multi handle they cost the slowest of the four, which is the
     * difference between a dashboard that paints and one that times out.
     *
     * @param list<string> $calls any of: default, sales, purchase, inventory, collections
     * @return array<string, array{ok:bool, status:int, body:?array, error:?string}>
     */
    public function bundle(Context $ctx, string $from, string $to, array $calls): array
    {
        $paths = [
            'default'     => 'dashboard/default',
            'sales'       => 'dashboard/sales',
            'purchase'    => 'dashboard/purchase',
            'inventory'   => 'dashboard/inventory',
            'collections' => 'dashboard/collections',
        ];

        $scope = ['from' => $from, 'to' => $to] + $ctx->asQuery();
        $headers = $this->authHeaders();

        $batch = [];
        foreach ($calls as $call) {
            if (!isset($paths[$call])) {
                continue;
            }
            $batch[$call] = ['path' => $paths[$call] . self::query($scope), 'headers' => $headers];
        }

        return $this->parallel($batch);
    }

    // -----------------------------------------------------------------------
    // Reports
    // -----------------------------------------------------------------------

    public function profitLoss(Context $ctx, string $from, string $to): array
    {
        return $this->read('reports/profit-loss' . self::query(['from' => $from, 'to' => $to] + $ctx->asQuery()));
    }

    public function balanceSheet(Context $ctx, string $asOn): array
    {
        return $this->read('reports/balance-sheet' . self::query(['to' => $asOn] + $ctx->asQuery()));
    }

    public function trialBalance(Context $ctx, string $from, string $to): array
    {
        return $this->read('reports/trial-balance' . self::query(['from' => $from, 'to' => $to] + $ctx->asQuery()));
    }

    public function cashFlow(Context $ctx, string $from, string $to, string $method = 'direct'): array
    {
        return $this->read('reports/cash-flow' . self::query([
            'from'   => $from,
            'to'     => $to,
            'method' => $method,
            'detail' => 'condensed',
        ] + $ctx->asQuery()));
    }

    /**
     * Open items for ONE party.
     *
     * Bounded on purpose: bill-by-bill takes a single acc_id, so this is called
     * for a capped list of parties somebody is actually looking at, and never
     * in a loop over every debtor in the ledger. A company-wide ageing comes
     * from the dashboard endpoints, which Books aggregates itself.
     */
    public function billByBill(Context $ctx, int $accountId, string $asOn): array
    {
        return $this->read('reports/bill-by-bill' . self::query([
            'acc_id' => $accountId,
            'to'     => $asOn,
        ] + $ctx->asQuery()));
    }

    /** Ledger accounts — for party pickers and for resolving a drill-down target. */
    public function accounts(Context $ctx, array $filters = []): array
    {
        return $this->read('masters/accounts' . self::query($filters + ['limit' => 100] + $ctx->asQuery()));
    }

    /** What this caller may do in Books. Drives the honest "you cannot see this" state. */
    public function accessMe(Context $ctx): array
    {
        return $this->read('access/me' . self::query($ctx->asQuery()));
    }
}
