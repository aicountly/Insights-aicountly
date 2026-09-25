<?php

declare(strict_types=1);

/**
 * A stand-in for Manage, Smart Books and Inventory.
 *
 * It answers the EXACT ROUTES AND PAYLOAD SHAPES read out of those
 * repositories — `data.kpis.total_sales`, `meta.summary.total_value`,
 * `receivables_ageing.b_31_60` and the rest — so a test that passes here is a
 * test against the contracts the adapters actually bind to. Where a shape is
 * wrong, the test fails for the same reason production would.
 *
 * It also models the things that go wrong, because those are what the product
 * has to handle well:
 *
 *   company 99   Manage refuses it — the cross-tenant case
 *   company 77   Books answers 403 — a viewer without reports access
 *   ?fail=books  Books is unreachable — the "unavailable is not zero" case
 *   ?partial=1   Inventory reports more rows than it returned
 *
 * Run: php -S 127.0.0.1:8792 tests/stub/router.php
 */

$path = trim((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'), '/');

// A base URL of http://host/fail-books is how the test suite points ONE client
// at a broken product without a second stub process: the prefix is stripped
// here and remembered as the product to fail.
$failPrefix = '';
if (preg_match('#^fail-([a-z]+)/#', $path, $m) === 1) {
    $failPrefix = $m[1];
    $path = substr($path, strlen($m[0]));
}

$path = preg_replace('#^api/#', '', $path) ?? $path;

$cmpId = (int) ($_GET['comp_id'] ?? $_GET['cmp_id'] ?? 0);
$fail = $failPrefix !== '' ? $failPrefix : (string) ($_GET['fail'] ?? '');

function reply(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Four dates spread across a window, so a trend has something to draw.
 *
 * @return list<string>
 */
function trendDates(string $from, string $to): array
{
    $start = strtotime($from) ?: strtotime('2026-01-01');
    $end = strtotime($to) ?: $start;
    $days = max(1, (int) round(($end - $start) / 86400));

    $out = [];
    foreach ([0.13, 0.36, 0.59, 0.82] as $at) {
        $out[] = date('Y-m-d', $start + (int) round($days * $at) * 86400);
    }

    return array_values(array_unique($out));
}

// ---------------------------------------------------------------------------
// The auth portal
//
// my.aicountly.com owns every token, and a live staff session cannot be minted
// in a development container — AuthFilter validates against the real portal.
// These three routes stand in for it so the browser verification exercises the
// REAL request path (relay → seskey → Bearer → validatesession → uuid) rather
// than a bypass bolted into the API.
//
// The tokens are deliberately legible: `auth-<uuid>` mints `ses-<uuid>`, which
// validates back to `<uuid>`. That is what lets one browser run sign in as the
// owner, a colleague and a restricted viewer in turn and prove that each sees
// something different. PORTAL_AUTH_BASE points here; it defaults to
// my.aicountly.com everywhere else, so nothing about this reaches production.
// ---------------------------------------------------------------------------

function bearer(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    return preg_match('/Bearer\s+(.+)/i', $header, $m) === 1 ? trim($m[1]) : '';
}

if ($path === 'seskey' || $path === 'seskey/refresh') {
    $authToken = bearer();
    if ($authToken === '' || !str_starts_with($authToken, 'auth-')) {
        reply(401, ['status' => 0, 'message' => 'Invalid auth token.']);
    }

    reply(200, [
        'status'     => 1,
        'ses_key'    => 'ses-' . substr($authToken, strlen('auth-')),
        'expires_in' => 900,
    ]);
}

if ($path === 'refresh_authtoken') {
    $authToken = bearer();
    if ($authToken === '' || !str_starts_with($authToken, 'auth-')) {
        reply(401, ['status' => 0, 'message' => 'Invalid auth token.']);
    }
    reply(200, ['status' => 1, 'auth_token' => $authToken]);
}

if ($path === 'validatesession') {
    $sesKey = bearer();
    if ($sesKey === '' || !str_starts_with($sesKey, 'ses-')) {
        reply(401, ['status' => 0, 'message' => 'Invalid or expired session.']);
    }

    $uuid = substr($sesKey, strlen('ses-'));
    reply(200, [
        'status'      => 1,
        'uuid_aictly' => $uuid,
        'user_name'   => ucfirst(str_replace('-', ' ', $uuid)),
    ]);
}

// ---------------------------------------------------------------------------
// Manage
// ---------------------------------------------------------------------------

if ($path === 'companyinfo') {
    // 99 is the company nobody in these tests may open.
    if ($cmpId === 99) {
        reply(403, ['message' => 'You do not have access to this company.']);
    }
    if ($cmpId <= 0) {
        reply(422, ['message' => 'comp_id is required.']);
    }

    reply(200, ['data' => [
        'cmp_id'      => $cmpId,
        'cmp_name'    => 'Test Trading Co ' . $cmpId,
        // 1 = owner, per CompanyAccess::OWNER. Company 77 is a member, not an
        // owner, so the owner bypass does not fire for it.
        'access_type' => $cmpId === 77 ? 0 : 1,
        'financial_years' => [
            ['fy_id' => 1, 'fy_from' => '2025-04-01', 'fy_to' => '2026-03-31'],
        ],
        'branches' => [
            ['bo_id' => 1, 'bo_name' => 'Head office'],
            ['bo_id' => 2, 'bo_name' => 'Warehouse branch'],
        ],
    ]]);
}

if ($path === 'companies') {
    reply(200, ['data' => [
        ['cmp_id' => 1, 'cmp_name' => 'Test Trading Co 1', 'access_type' => 1],
        ['cmp_id' => 77, 'cmp_name' => 'Restricted Co', 'access_type' => 0],
    ], 'meta' => ['total' => 2]]);
}

// ---------------------------------------------------------------------------
// Smart Books
// ---------------------------------------------------------------------------

if (str_starts_with($path, 'dashboard/')) {
    if ($fail === 'books') {
        // A transport failure, as the adapter would see it.
        reply(503, ['message' => 'Books is down.']);
    }
    if ($cmpId === 77) {
        reply(403, ['message' => 'Insufficient permissions.']);
    }

    $from = (string) ($_GET['from'] ?? '2026-01-01');
    $to = (string) ($_GET['to'] ?? '2026-01-31');

    // Figures move with the window, so a comparison of two windows is a real
    // comparison rather than the same number twice. January 2026 and December
    // 2025 keep the exact values the integration tests assert against; every
    // other month gets a deterministic scale of its own, which is what makes a
    // browser walk-through show a real change instead of a row of 0.0%.
    $scale = match (substr($from, 0, 7)) {
        '2026-01' => 1.0,
        '2025-12' => 0.8,
        default   => round(0.75 + ((int) substr($from, 5, 2) % 6) * 0.08, 4),
    };

    // Trend points land INSIDE the requested window. A stub that always answers
    // with January's dates draws a flat line for every other period, which
    // hides exactly the bug a chart check is looking for.
    $points = trendDates($from, $to);

    $board = substr($path, strlen('dashboard/'));

    if ($board === 'sales') {
        reply(200, ['data' => [
            'context' => ['from' => $from, 'to' => $to],
            'kpis' => [
                'total_sales'         => round(1250000.55 * $scale, 4),
                'taxable_sales'       => round(1059322.50 * $scale, 4),
                'gst_collected'       => round(190678.05 * $scale, 4),
                'total_invoices'      => (int) round(214 * $scale),
                'avg_invoice_value'   => round(5841.12 * $scale, 4),
                'receivables'         => 845000.25,
                'overdue_receivables' => 312400.10,
            ],
            'prev_period_kpis' => ['total_sales' => 1000000.44],
            'trend' => ['granularity' => 'day', 'points' => [
                ['date' => $points[0], 'value' => round(250000.10 * $scale, 4)],
                ['date' => $points[1], 'value' => round(400000.20 * $scale, 4)],
                ['date' => $points[2], 'value' => round(300000.15 * $scale, 4)],
                ['date' => $points[3], 'value' => round(300000.10 * $scale, 4)],
            ]],
            'top_customers' => [
                ['acc_id' => 501, 'label' => 'Sharma Distributors', 'amount' => 600000.00],
                ['acc_id' => 502, 'label' => '=cmd|calc', 'amount' => 400000.25],
                ['acc_id' => 503, 'label' => 'Mehta Retail', 'amount' => 250000.30],
            ],
            'top_items' => [
                ['item_id' => 9001, 'label' => 'Steel rod 12mm', 'amount' => 500000.00],
            ],
            // The bucket keys Books actually uses. `total` is present and must
            // NOT be charted beside the others.
            'receivables_ageing' => [
                'not_due'   => 400000.00,
                'b_0_30'    => 200000.15,
                'b_31_60'   => 145000.00,
                'b_61_90'   => 60000.10,
                'b_90_plus' => 40000.00,
                'total'     => 845000.25,
            ],
            'register_summary' => [
                'total_invoices'       => 214,
                'sales_returns_amount' => 45000.75,
            ],
        ]]);
    }

    if ($board === 'purchase') {
        reply(200, ['data' => [
            'kpis' => [
                'total_purchases'   => round(760000.40 * $scale, 4),
                'taxable_purchases' => round(644067.80 * $scale, 4),
                'input_gst'         => round(115932.60 * $scale, 4),
                'payables'          => 415000.00,
                'overdue_payables'  => 98000.50,
            ],
            'payables_ageing' => [
                'not_due' => 300000.00, 'b_0_30' => 60000.00, 'b_31_60' => 30000.00,
                'b_61_90' => 15000.00, 'b_90_plus' => 10000.00, 'total' => 415000.00,
            ],
            'top_suppliers' => [
                ['acc_id' => 701, 'label' => 'Bharat Steel', 'amount' => 500000.00],
            ],
            'top_items' => [],
            'trend' => ['points' => [['date' => $points[1], 'value' => round(760000.40 * $scale, 4)]]],
        ]]);
    }

    if ($board === 'collections') {
        reply(200, ['data' => [
            'kpis' => [
                // `collections` is how far customer balances fell;
                // `cash_received` is what actually arrived. The gap is discount
                // allowed and TDS on the same vouchers, and a test that used the
                // same number for both would prove nothing about the pair.
                'collections'       => round(910000.30 * $scale, 4),
                'cash_received'     => round(884300.30 * $scale, 4),
                'receipt_count'     => 180,
                'payments_made'     => round(520000.00 * $scale, 4),
                'cash_paid'         => round(516800.00 * $scale, 4),
                'payment_count'     => 96,
                'net_cash_movement' => round(367500.30 * $scale, 4),
                'unattributed'      => ['receipt_vouchers' => 2, 'payment_vouchers' => 0],
            ],
            'trend' => ['granularity' => 'day', 'points' => [
                ['date' => $points[0], 'collections' => round(300000.10 * $scale, 4), 'payments' => round(150000.00 * $scale, 4)],
                ['date' => $points[1], 'collections' => round(260000.10 * $scale, 4), 'payments' => round(170000.00 * $scale, 4)],
                ['date' => $points[2], 'collections' => round(200000.05 * $scale, 4), 'payments' => round(100000.00 * $scale, 4)],
                ['date' => $points[3], 'collections' => round(150000.05 * $scale, 4), 'payments' => round(100000.00 * $scale, 4)],
            ]],
            'top_collections' => [['acc_id' => 501, 'label' => 'Sharma Distributors', 'amount' => 500000.00]],
            'top_payments'    => [['acc_id' => 701, 'label' => 'Bharat Steel', 'amount' => 320000.00]],
        ]]);
    }

    if ($board === 'default') {
        reply(200, ['data' => [
            'kpis' => [
                'income'        => round(1250000.55 * $scale, 4),
                'expense'       => round(940000.25 * $scale, 4),
                'net_profit'    => round(310000.30 * $scale, 4),
                'assets'        => 4500000.00,
                'liabilities'   => 2100000.00,
                'equity'        => 2400000.00,
                'cash_and_bank' => 275000.75,
                'receivables'   => 845000.25,
                'payables'      => 415000.00,
                'stock_value'   => 1880000.00,
                'gst_payable'   => 74745.45,
            ],
            'prev_month_kpis' => ['income' => 1000000.00],
            'top_expenses'    => [
                ['acc_id' => 801, 'acc_name' => 'Freight outward', 'amount' => 220000.00],
                ['acc_id' => 802, 'acc_name' => 'Rent', 'amount' => 180000.00],
            ],
        ]]);
    }

    if ($board === 'inventory') {
        reply(200, ['data' => ['kpis' => ['stock_value' => 1880000.00]]]);
    }

    reply(404, ['message' => 'No such board.']);
}

if ($path === 'access/me') {
    if ($fail === 'books') {
        reply(503, ['message' => 'Books is down.']);
    }
    if ($cmpId === 77) {
        reply(403, ['message' => 'Insufficient permissions.']);
    }
    reply(200, ['data' => ['permissions' => ['dashboard.read', 'reports.trial_balance.read']]]);
}

if (str_starts_with($path, 'reports/bill-by-bill')) {
    if (($_GET['acc_id'] ?? '') === '') {
        reply(400, ['message' => 'acc_id required']);
    }
    reply(200, ['data' => ['rows' => [
        ['bill_ref' => 'INV-1', 'due_date' => '2025-12-01', 'pending_amount' => 120000.00],
    ]]]);
}

// ---------------------------------------------------------------------------
// Inventory
// ---------------------------------------------------------------------------

if ($path === 'v1/valuation') {
    if ($fail === 'inventory') {
        reply(503, ['message' => 'Inventory is down.']);
    }

    $limit = (int) ($_GET['limit'] ?? 1);
    $rows = [];
    $catalogue = [
        ['item_id' => 9001, 'item_name' => 'Steel rod 12mm', 'warehouse_id' => 1, 'warehouse_name' => 'Head office', 'value' => 900000.00],
        ['item_id' => 9002, 'item_name' => 'Cement OPC 53', 'warehouse_id' => 2, 'warehouse_name' => 'Warehouse branch', 'value' => 620000.50],
        ['item_id' => 9003, 'item_name' => 'Paint 20L', 'warehouse_id' => 1, 'warehouse_name' => 'Head office', 'value' => 360000.00],
    ];
    foreach (array_slice($catalogue, 0, max(1, $limit)) as $row) {
        $rows[] = $row;
    }

    reply(200, [
        'data' => $rows,
        'meta' => [
            // `total` exceeds what was returned, which is what a ranking must
            // report as partial rather than presenting as the whole stock.
            'total'  => count($catalogue),
            'limit'  => $limit,
            'offset' => 0,
            'summary' => [
                'as_of'       => (string) ($_GET['as_of'] ?? '2026-01-31'),
                'method'      => 'weighted_average',
                'total_qty'   => 4820.500,
                'total_value' => 1880000.50,
                'item_count'  => 3,
            ],
        ],
    ]);
}

if ($path === 'v1/reports/stock-ageing') {
    if ($fail === 'inventory') {
        reply(503, ['message' => 'Inventory is down.']);
    }

    $partial = ($_GET['partial'] ?? '') === '1';

    reply(200, [
        'data' => [
            ['item_id' => 9001, 'age_bucket' => '0-30', 'value' => 900000.00],
            ['item_id' => 9002, 'age_bucket' => '31-90', 'value' => 520000.50],
            ['item_id' => 9003, 'age_bucket' => '91-180', 'value' => 300000.00],
            ['item_id' => 9004, 'age_bucket' => '180+', 'value' => 160000.00],
        ],
        'meta' => ['total' => $partial ? 400 : 4, 'limit' => 200, 'offset' => 0],
    ]);
}

if ($path === 'v1/access/me') {
    if ($fail === 'inventory') {
        reply(503, ['message' => 'Inventory is down.']);
    }
    reply(200, ['data' => ['permissions' => ['reports.valuation.read']]]);
}

// ---------------------------------------------------------------------------
// The operational products' probe
// ---------------------------------------------------------------------------

if ($path === 'v1/permissions' || $path === 'v1/session') {
    reply(200, ['data' => ['permissions' => ['order.view']]]);
}

if ($path === 'health') {
    reply(200, ['status' => 'ok']);
}

reply(404, ['message' => 'Stub has no route for ' . $path]);
