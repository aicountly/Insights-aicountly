<?php

declare(strict_types=1);

namespace Aicountly\Api\Metrics;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Ids;

/**
 * KPIs a firm defined for itself.
 *
 * A custom KPI is a NAME, a SENTENCE and a FORMULA over catalogue metrics. It
 * is compiled by Expression before it is stored — so an unparseable or
 * meaningless formula is rejected at the point somebody typed it, with a
 * message about what is wrong, rather than becoming a card that is permanently
 * blank.
 *
 * THE STORED AST IS THE ONE THAT WAS CHECKED. Query time re-reads the tree from
 * the row rather than re-parsing the text, so a formula cannot mean one thing
 * when it was validated and another when it runs.
 *
 * Every read is company-scoped. A KPI belongs to the company that defined it
 * and is invisible from any other, which is not a nicety: the formula text
 * itself can describe a business ("commission = net_revenue * 0.02").
 */
final class CustomMetrics
{
    public const TABLE = 'insights_metric_definitions';

    /** How many a company may define. A catalogue, not a spreadsheet. */
    public const MAX_PER_COMPANY = 200;

    /** The grains a custom KPI falls back to when its inputs constrain nothing. */
    public const DEFAULT_GRAINS = ['day', 'week', 'month', 'quarter', 'year'];

    /** @var array<string, array<string, mixed>|null> */
    private static array $rowMemo = [];

    /** @var array<string, MetricDefinition|null> */
    private static array $definitionMemo = [];

    /**
     * Every active custom KPI for a company.
     *
     * @return list<MetricDefinition>
     */
    public static function all(Context $ctx): array
    {
        $rows = Db::all(
            'SELECT * FROM ' . self::TABLE . ' WHERE cmp_id = :cmp AND is_active = TRUE ORDER BY label ASC',
            ['cmp' => $ctx->cmpId],
        );

        $out = [];
        foreach ($rows as $row) {
            self::$rowMemo[self::memoKey($ctx, (string) $row['metric_key'])] = $row;
            $out[] = self::toDefinition($row);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> raw rows, for the management screen */
    public static function rows(Context $ctx): array
    {
        return Db::all(
            'SELECT * FROM ' . self::TABLE . ' WHERE cmp_id = :cmp ORDER BY is_active DESC, label ASC',
            ['cmp' => $ctx->cmpId],
        );
    }

    /**
     * True for an id this store could resolve, without loading it.
     *
     * Deliberately permissive on SHAPE only — `custom.*` — because resolving it
     * needs a company and this is called where there is not one yet.
     */
    public static function exists(string $metricId): bool
    {
        return preg_match('/^custom\.[a-z][a-z0-9_]{0,48}$/', $metricId) === 1;
    }

    public static function definition(Context $ctx, string $metricId): ?MetricDefinition
    {
        $key = self::memoKey($ctx, $metricId);
        if (array_key_exists($key, self::$definitionMemo)) {
            return self::$definitionMemo[$key];
        }

        $row = self::row($ctx, $metricId);

        return self::$definitionMemo[$key] = $row === null ? null : self::toDefinition($row);
    }

    /** The compiled formula for a custom KPI, or null. */
    public static function expression(Context $ctx, string $metricId): ?Expression
    {
        $row = self::row($ctx, $metricId);
        if ($row === null) {
            return null;
        }

        try {
            // Recompiled from the stored TEXT, and then checked against the
            // stored tree: if the catalogue has changed under a saved KPI —
            // a metric renamed, a unit corrected — this is where it surfaces,
            // rather than at the moment somebody exports a report from it.
            return Expression::compile((string) $row['formula'], (string) $row['metric_key']);
        } catch (ExpressionError $e) {
            error_log(sprintf(
                '[insights][metrics] stored KPI %s for company %d no longer compiles: %s',
                $row['metric_key'],
                $ctx->cmpId,
                $e->getMessage(),
            ));

            return null;
        }
    }

    /**
     * Create or replace a custom KPI.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> the stored row
     * @throws ExpressionError when the formula cannot be accepted
     */
    public static function save(Context $ctx, Auth $auth, array $input, ?string $existingKey = null): array
    {
        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 120) {
            throw new ExpressionError('Give the KPI a name of up to 120 characters.', 'invalid_label');
        }

        $key = $existingKey ?? self::slug($label);
        if (!self::exists($key)) {
            throw new ExpressionError('That name cannot be turned into a KPI id. Use letters, numbers and spaces.', 'invalid_key');
        }

        $expression = Expression::compile((string) ($input['formula'] ?? ''), $key);
        $shape = $expression->check(static function (string $id) use ($ctx): ?MetricDefinition {
            return MetricCatalog::get($id) ?? self::definition($ctx, $id);
        });

        // A KPI that reads another KPI is allowed; a chain that loops is not.
        self::assertNoCycle($ctx, $key, $expression->references());

        if ($existingKey === null) {
            $count = (int) Db::scalar(
                'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE cmp_id = :cmp AND is_active = TRUE',
                ['cmp' => $ctx->cmpId],
            );
            if ($count >= self::MAX_PER_COMPANY) {
                throw new ExpressionError('This company already has ' . self::MAX_PER_COMPANY . ' KPIs, which is the limit. Retire one first.', 'limit_reached');
            }
        }

        $unit = in_array((string) ($input['unit'] ?? ''), MetricDefinition::UNITS, true)
            ? (string) $input['unit']
            // The checker worked out what the formula produces. Trusting that
            // over the user's dropdown is what stops a ratio being labelled
            // rupees.
            : ($shape['unit'] === 'scalar' ? 'count' : $shape['unit']);

        $betterWhen = in_array((string) ($input['better_when'] ?? ''), ['up', 'down', 'neutral'], true)
            ? (string) $input['better_when']
            : 'up';

        $values = [
            'cmp_id'           => $ctx->cmpId,
            'metric_key'       => $key,
            'label'            => $label,
            'definition_text'  => mb_substr(trim((string) ($input['definition'] ?? '')), 0, 600),
            'formula'          => $expression->source,
            'ast'              => Db::json($expression->tree()),
            'depends_on'       => Db::json($expression->references()),
            'unit'             => $unit,
            'precision'        => max(0, min(6, (int) ($input['precision'] ?? $shape['precision']))),
            'better_when'      => $betterWhen,
            'accounting_basis' => $shape['basis'] === 'scalar' ? 'derived' : $shape['basis'],
            'grains'           => Db::json($shape['grains'] === [] ? self::DEFAULT_GRAINS : $shape['grains']),
        ];

        $existing = self::row($ctx, $key);
        self::forget($ctx, $key);

        if ($existing !== null) {
            // A changed formula is a changed definition, so the version moves.
            // A report exported last quarter and one exported today must be
            // distinguishable when the meaning changed in between.
            $version = (string) $existing['formula_version'];
            if ((string) $existing['formula'] !== $expression->source) {
                $version = self::bump($version);
            }

            Db::update(self::TABLE, $values + [
                'formula_version' => $version,
                'is_active'       => true,
                'updated_at'      => gmdate('c'),
            ], ['cmp_id' => $ctx->cmpId, 'metric_key' => $key]);

            return (array) self::row($ctx, $key);
        }

        Db::insert(self::TABLE, $values + [
            'public_id'  => Ids::public('kpi'),
            'created_by' => $auth->uuid,
        ], 'metric_id');

        return (array) self::row($ctx, $key);
    }

    /** Retire a KPI. Kept, not deleted, so a dashboard that used it can explain itself. */
    public static function retire(Context $ctx, string $metricKey): bool
    {
        self::forget($ctx, $metricKey);

        return Db::update(
            self::TABLE,
            ['is_active' => false, 'updated_at' => gmdate('c')],
            ['cmp_id' => $ctx->cmpId, 'metric_key' => $metricKey],
        ) > 0;
    }

    /**
     * Refuse a definition chain that loops.
     *
     * @param list<string> $references
     */
    private static function assertNoCycle(Context $ctx, string $key, array $references, int $depth = 0): void
    {
        if ($depth > 6) {
            throw new ExpressionError('These KPIs refer to one another too deeply. Simplify the chain.', 'too_deep');
        }

        foreach ($references as $reference) {
            if ($reference === $key) {
                throw new ExpressionError('That would make ' . $key . ' depend on itself.', 'cyclic', ['metric_id' => $reference]);
            }
            if (!self::exists($reference)) {
                continue;
            }
            $row = self::row($ctx, $reference);
            if ($row === null) {
                continue;
            }
            $nested = Db::jsonColumn($row['depends_on'] ?? null);
            self::assertNoCycle($ctx, $key, array_values(array_filter($nested, 'is_string')), $depth + 1);
        }
    }

    /** @return array<string, mixed>|null */
    private static function row(Context $ctx, string $metricKey): ?array
    {
        $key = self::memoKey($ctx, $metricKey);
        if (array_key_exists($key, self::$rowMemo)) {
            return self::$rowMemo[$key];
        }

        return self::$rowMemo[$key] = Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE cmp_id = :cmp AND metric_key = :key AND is_active = TRUE',
            ['cmp' => $ctx->cmpId, 'key' => $metricKey],
        );
    }

    /** @param array<string, mixed> $row */
    private static function toDefinition(array $row): MetricDefinition
    {
        $grains = array_values(array_filter(Db::jsonColumn($row['grains'] ?? null), 'is_string'));

        return new MetricDefinition(
            id: (string) $row['metric_key'],
            label: (string) $row['label'],
            definition: (string) ($row['definition_text'] ?: 'Custom KPI: ' . $row['formula']),
            owningProduct: 'insights',
            binding: 'custom formula: ' . $row['formula'],
            measure: (string) $row['formula'],
            aggregation: 'derived',
            unit: (string) $row['unit'],
            precision: (int) $row['precision'],
            dimensions: [],
            grains: $grains === [] ? self::DEFAULT_GRAINS : $grains,
            accountingBasis: (string) $row['accounting_basis'],
            sourcePermissions: [],
            betterWhen: (string) $row['better_when'],
            drilldown: null,
            formulaVersion: (string) $row['formula_version'],
            isBalance: false,
            dependsOn: array_values(array_filter(Db::jsonColumn($row['depends_on'] ?? null), 'is_string')),
        );
    }

    private static function slug(string $label): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $label));
        $slug = trim($slug, '_');
        $slug = (string) preg_replace('/^[^a-z]+/', '', $slug);

        return 'custom.' . mb_substr($slug === '' ? 'kpi' : $slug, 0, 48);
    }

    private static function bump(string $version): string
    {
        $parts = array_map('intval', explode('.', $version) + [0, 0, 0]);
        $parts[1]++;

        return $parts[0] . '.' . $parts[1] . '.0';
    }

    private static function memoKey(Context $ctx, string $metricKey): string
    {
        return $ctx->cmpId . '|' . $metricKey;
    }

    private static function forget(Context $ctx, string $metricKey): void
    {
        unset(self::$rowMemo[self::memoKey($ctx, $metricKey)], self::$definitionMemo[self::memoKey($ctx, $metricKey)]);
    }

    /** Tests only. */
    public static function forgetAll(): void
    {
        self::$rowMemo = [];
        self::$definitionMemo = [];
    }
}
