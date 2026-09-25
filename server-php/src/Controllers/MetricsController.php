<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Dashboards\WidgetSchema;
use Aicountly\Api\Http;
use Aicountly\Api\Metrics\CustomMetrics;
use Aicountly\Api\Metrics\Expression;
use Aicountly\Api\Metrics\ExpressionError;
use Aicountly\Api\Metrics\MetricCatalog;
use Aicountly\Api\Metrics\MetricDefinition;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Period;

/**
 * The metric catalogue, and the KPIs a firm has defined for itself.
 *
 * The catalogue is the contract between the builder and the data: everything a
 * widget may ask for is here, with its definition, its owner, its unit and the
 * endpoint it is read from. A screen that offers a metric this endpoint does
 * not list is a screen offering something the backend will refuse.
 */
final class MetricsController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'metric.view');

        $catalogue = [];
        foreach (MetricCatalog::all() as $definition) {
            $catalogue[] = $definition->jsonSerialize();
        }

        $custom = [];
        foreach (CustomMetrics::all($ctx) as $definition) {
            $custom[] = $definition->jsonSerialize() + ['is_custom' => true];
        }

        Http::data([
            'metrics'    => $catalogue,
            'custom'     => $custom,
            'dimensions' => MetricCatalog::DIMENSIONS,
            'grains'     => Period::GRAINS,
            'units'      => MetricDefinition::UNITS,
            'functions'  => array_keys(Expression::functions()),
            'widget_types' => WidgetSchema::TYPES,
            'can_manage' => Permissions::allows($ctx, $auth, 'metric.manage'),
        ]);
    }

    /**
     * Check a formula without saving it.
     *
     * The builder calls this as somebody types, so an error arrives while they
     * are still looking at the field rather than when they press Save.
     */
    public static function validate(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'metric.view');

        $formula = (string) (Http::param('formula') ?? '');

        try {
            $expression = Expression::compile($formula);
            $shape = $expression->check(static fn (string $id) => MetricCatalog::get($id) ?? CustomMetrics::definition($ctx, $id));
        } catch (ExpressionError $e) {
            Http::data([
                'valid'   => false,
                'code'    => $e->errorCode,
                'message' => $e->getMessage(),
                'details' => $e->details,
            ]);
        }

        Http::data([
            'valid'      => true,
            'references' => $expression->references(),
            'unit'       => $shape['unit'],
            'precision'  => $shape['precision'],
            'basis'      => $shape['basis'],
            'grains'     => $shape['grains'],
            'is_balance' => $shape['is_balance'],
        ]);
    }

    public static function saveCustom(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'metric.manage');

        try {
            $row = CustomMetrics::save($ctx, $auth, Http::body());
        } catch (ExpressionError $e) {
            Http::validationFailed($e->getMessage(), ['field' => 'formula', 'code' => $e->errorCode] + $e->details);
        }

        Http::data(self::presentCustom($row), 201);
    }

    public static function updateCustom(string $metricKey): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'metric.manage');

        $metricKey = self::normaliseKey($metricKey);

        try {
            $row = CustomMetrics::save($ctx, $auth, Http::body(), $metricKey);
        } catch (ExpressionError $e) {
            Http::validationFailed($e->getMessage(), ['field' => 'formula', 'code' => $e->errorCode] + $e->details);
        }

        Http::data(self::presentCustom($row));
    }

    public static function retireCustom(string $metricKey): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'metric.manage');

        if (!CustomMetrics::retire($ctx, self::normaliseKey($metricKey))) {
            Http::notFound('There is no KPI with that id in this company.');
        }

        Http::data(['retired' => true]);
    }

    /**
     * A KPI id arrives in a URL segment, where a dot is awkward. Both
     * `custom.gross_take` and `custom_gross_take` resolve to the same KPI.
     */
    private static function normaliseKey(string $raw): string
    {
        $key = strtolower(trim(rawurldecode($raw)));
        if (!str_contains($key, '.') && str_starts_with($key, 'custom_')) {
            $key = 'custom.' . substr($key, strlen('custom_'));
        }

        if (!CustomMetrics::exists($key)) {
            Http::validationFailed('That is not a custom KPI id.', ['field' => 'metric_key']);
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentCustom(array $row): array
    {
        return [
            'id'              => $row['metric_key'],
            'public_id'       => $row['public_id'],
            'label'           => $row['label'],
            'definition'      => $row['definition_text'],
            'formula'         => $row['formula'],
            'unit'            => $row['unit'],
            'precision'       => (int) $row['precision'],
            'better_when'     => $row['better_when'],
            'depends_on'      => \Aicountly\Api\Db::jsonColumn($row['depends_on']),
            'formula_version' => $row['formula_version'],
            'is_active'       => (bool) $row['is_active'],
            'updated_at'      => $row['updated_at'],
        ];
    }
}
