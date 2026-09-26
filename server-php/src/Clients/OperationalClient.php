<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Context;
use Aicountly\Api\Env;

/**
 * The operational products — Sales, Purchases, Billing and POS.
 *
 * They share one shape (`/api/v1/...`, Bearer ses_key, cmp_id/fy_id/bo_id) and
 * one role in Insights, so they share one client configured four ways rather
 * than four near-identical files.
 *
 * WHAT THEY MAY AND MAY NOT CONTRIBUTE. These products own ORDERS, QUOTATIONS,
 * TILL SESSIONS, REQUISITIONS and the operational state of a document. They do
 * NOT contribute revenue, receivables, tax or margin, however tempting their
 * dashboards make it look: a sale entered at a POS till, invoiced in Billing
 * and posted to Books is ONE sale, and Books is the one place it is recorded as
 * revenue. Adding POS takings to Books' net sales is the double count this
 * product exists to avoid, and the metric catalogue enforces it — every metric
 * with `accounting_basis` other than `operational` binds to Books or Inventory
 * and to nothing else.
 *
 * VERIFIED CONTRACTS:
 *   Sales     GET v1/dashboard/overview | pipeline | fulfilment | collections | forecast
 *             GET v1/permissions, GET v1/session
 *   Purchases GET v1/dashboards/{view}   (overview | procurement | suppliers | bills | insights)
 *   Billing   GET v1/dashboards/overview | receivables | payables | cash-compliance
 *             GET v1/receivables?as_on, GET v1/payables, GET v1/cash-bank
 *   POS       GET v1/dashboards/overview | retail | restaurant | customers | controls
 *             GET v1/dashboard (single-day summary)
 */
final class OperationalClient extends ApiClient
{
    /** @var array<string, array{label:string, prod:string, sandbox:string, env:string}> */
    private const PRODUCTS = [
        'sales' => [
            'label'   => 'Sales',
            'prod'    => 'https://sales.aicountly.com',
            'sandbox' => 'https://sales.gh.aicountly.com',
            'env'     => 'SALES_API_BASE',
        ],
        'purchases' => [
            'label'   => 'Purchases',
            'prod'    => 'https://purchases.aicountly.com',
            'sandbox' => 'https://purchases.gh.aicountly.com',
            'env'     => 'PURCHASES_API_BASE',
        ],
        'billing' => [
            'label'   => 'Billing',
            'prod'    => 'https://billing.aicountly.com',
            'sandbox' => 'https://billing.gh.aicountly.com',
            'env'     => 'BILLING_API_BASE',
        ],
        'pos' => [
            'label'   => 'POS',
            'prod'    => 'https://pos.aicountly.com',
            'sandbox' => 'https://pos.gh.aicountly.com',
            'env'     => 'POS_API_BASE',
        ],
    ];

    private string $authorization = '';

    private function __construct(private readonly string $product)
    {
    }

    public static function for(string $product): self
    {
        $product = strtolower(trim($product));
        if (!isset(self::PRODUCTS[$product])) {
            throw new \InvalidArgumentException('Unknown operational product: ' . $product);
        }

        return new self($product);
    }

    /** @return list<string> */
    public static function products(): array
    {
        return array_keys(self::PRODUCTS);
    }

    public static function labelFor(string $product): string
    {
        return self::PRODUCTS[strtolower($product)]['label'] ?? ucfirst($product);
    }

    public function label(): string
    {
        return self::PRODUCTS[$this->product]['label'];
    }

    public function service(): string
    {
        return $this->product;
    }

    protected function productionBase(): string
    {
        return self::PRODUCTS[$this->product]['prod'];
    }

    protected function sandboxBase(): string
    {
        return self::PRODUCTS[$this->product]['sandbox'];
    }

    protected function baseEnvKey(): string
    {
        return self::PRODUCTS[$this->product]['env'];
    }

    /**
     * Whether an administrator has turned this source on for this deployment.
     *
     * A base URL always resolves (it is derived from our own hostname), so
     * "configured" here means explicitly enabled. Without that, a fresh
     * deployment would fan out to four products nobody has subscribed to and
     * report four timeouts as four broken integrations.
     */
    public function isEnabled(): bool
    {
        $raw = strtolower(trim(Env::get('INSIGHTS_SOURCES')));
        if ($raw === '') {
            // Unset means "the standard fleet", which is what a normal
            // deployment has. An operator narrows it, they do not have to
            // enumerate it.
            return true;
        }
        if ($raw === 'none') {
            return false;
        }

        $enabled = array_map('trim', explode(',', $raw));

        return in_array($this->product, $enabled, true);
    }

    public function withSession(string $sesKey): self
    {
        $this->authorization = 'Bearer ' . $sesKey;

        return $this;
    }

    private function read(string $path): array
    {
        return $this->request('GET', $path, null, ['Authorization' => $this->authorization]);
    }

    /**
     * The product's own overview board for this period.
     *
     * Each product spells the path a little differently — that is the whole
     * reason this method exists rather than the caller composing the URL.
     */
    public function overview(Context $ctx, string $from, string $to): array
    {
        $scope = ['from' => $from, 'to' => $to] + $ctx->asQuery();

        $path = match ($this->product) {
            'sales'     => 'v1/dashboard/overview',
            'purchases' => 'v1/dashboards/overview',
            'billing'   => 'v1/dashboards/overview',
            'pos'       => 'v1/dashboards/overview',
        };

        return $this->read($path . self::query($scope));
    }

    /** A named board, where the product offers more than one. */
    public function board(Context $ctx, string $view, string $from, string $to): array
    {
        $view = preg_replace('/[^a-z0-9-]/', '', strtolower($view)) ?? '';
        if ($view === '') {
            return ['ok' => false, 'status' => 400, 'body' => null, 'error' => 'A board name is required.'];
        }

        $scope = ['from' => $from, 'to' => $to] + $ctx->asQuery();
        $prefix = $this->product === 'sales' ? 'v1/dashboard/' : 'v1/dashboards/';

        return $this->read($prefix . $view . self::query($scope));
    }

    /**
     * What this caller may do in the product.
     *
     * Sales and POS expose `v1/permissions`; the others answer the same
     * question from `v1/session`. Both are cheap and both are read with the
     * caller's own key, which is the point: Insights never guesses at
     * somebody's access over there.
     */
    public function permissions(Context $ctx): array
    {
        $path = in_array($this->product, ['sales', 'pos'], true) ? 'v1/permissions' : 'v1/session';

        return $this->read($path . self::query($ctx->asQuery()));
    }

    /** A cheap liveness probe that does not need a company. */
    public function health(): array
    {
        return $this->request('GET', 'health', null, []);
    }
}
