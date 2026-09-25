<?php

declare(strict_types=1);

namespace Aicountly\Api\Reports;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Metrics\CustomMetrics;
use Aicountly\Api\Metrics\MetricCatalog;
use Aicountly\Api\Metrics\MetricResult;
use Aicountly\Api\Metrics\QueryService;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Format;
use Aicountly\Api\Support\Ids;
use Aicountly\Api\Support\Payload;
use Aicountly\Api\Support\Period;
use Aicountly\Api\Support\Sources;

/**
 * Saved reports, and the files they turn into.
 *
 * A REPORT DEFINITION IS SAVED; A REPORT RESULT IS NOT. Opening a saved report
 * re-asks the same question of the live products, which is why a report saved
 * in March and run in June shows June's figures under March's definition rather
 * than March's numbers under a June date.
 *
 * THE VIEWER'S PERMISSIONS ARE APPLIED AGAIN AT EXPORT TIME. An export is a
 * query like any other: it runs through the same QueryService, with the same
 * session, against the same sources. There is no "render the owner's copy"
 * path, so a shared report cannot become a way to download data the recipient
 * could not see on screen.
 *
 * EVERY FILE CARRIES ITS SCOPE. Title, company, period, filters, units, when it
 * was generated, how fresh each source was and any partial-data warning — on
 * the page, in the spreadsheet and in the CSV. A figure without its scope is a
 * figure somebody will quote out of context.
 */
final class ReportService
{
    public const TABLE = 'insights_report_definitions';

    public const FORMATS = ['pdf', 'csv', 'xlsx'];

    /** The most rows one export will contain. */
    public const MAX_ROWS = 5000;

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
        private readonly QueryService $query,
    ) {
    }

    // -----------------------------------------------------------------------
    // Definitions
    // -----------------------------------------------------------------------

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function index(int $limit = 50, int $offset = 0): array
    {
        $params = ['cmp' => $this->ctx->cmpId, 'uuid' => $this->auth->uuid];
        $where = 'cmp_id = :cmp AND NOT is_archived AND (owner_uuid = :uuid OR visibility IN (\'team\', \'organisation\'))';

        if ($this->auth->ownsCompany($this->ctx->cmpId) || $this->auth->isService()) {
            $where = 'cmp_id = :cmp AND NOT is_archived';
            unset($params['uuid']);
        }

        $total = (int) Db::scalar('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ' . $where, $params);
        $rows = Db::all(
            'SELECT * FROM ' . self::TABLE . ' WHERE ' . $where . ' ORDER BY updated_at DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => max(1, min(200, $limit)), 'offset' => max(0, $offset)],
        );

        return ['rows' => array_map([$this, 'present'], $rows), 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function find(string $publicId): ?array
    {
        $row = $this->row($publicId);

        return $row === null ? null : $this->present($row);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(array $input, ?string $publicId = null): array
    {
        Permissions::assert($this->ctx, $this->auth, 'report.manage');

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            Http::validationFailed('Give the report a name.', ['field' => 'title']);
        }

        $config = $this->config($input['config'] ?? []);
        $visibility = in_array((string) ($input['visibility'] ?? 'private'), ['private', 'team', 'organisation'], true)
            ? (string) $input['visibility']
            : 'private';

        if ($publicId !== null) {
            $existing = $this->row($publicId);
            if ($existing === null) {
                Http::notFound('That report does not exist.');
            }
            if ((string) $existing['owner_uuid'] !== $this->auth->uuid && !$this->auth->ownsCompany($this->ctx->cmpId)) {
                Http::forbidden('Only the person who created this report, or the company owner, can change it.');
            }

            Db::update(self::TABLE, [
                'title'       => mb_substr($title, 0, 160),
                'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 600),
                'config'      => Db::json($config),
                'visibility'  => $visibility,
                'updated_at'  => gmdate('c'),
                'updated_by'  => $this->auth->uuid,
            ], ['report_id' => (int) $existing['report_id']]);

            return (array) $this->find($publicId);
        }

        $newId = Ids::public('rep');
        Db::insert(self::TABLE, [
            'public_id'   => $newId,
            'cmp_id'      => $this->ctx->cmpId,
            'owner_uuid'  => $this->auth->uuid,
            'title'       => mb_substr($title, 0, 160),
            'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 600),
            'config'      => Db::json($config),
            'visibility'  => $visibility,
            'updated_by'  => $this->auth->uuid,
        ], 'report_id');

        return (array) $this->find($newId);
    }

    public function delete(string $publicId): void
    {
        Permissions::assert($this->ctx, $this->auth, 'report.manage');

        $row = $this->row($publicId);
        if ($row === null) {
            Http::notFound('That report does not exist.');
        }
        if ((string) $row['owner_uuid'] !== $this->auth->uuid && !$this->auth->ownsCompany($this->ctx->cmpId)) {
            Http::forbidden('Only the person who created this report, or the company owner, can delete it.');
        }

        Db::update(self::TABLE, ['is_archived' => true, 'updated_at' => gmdate('c')], ['report_id' => (int) $row['report_id']]);
    }

    // -----------------------------------------------------------------------
    // Running
    // -----------------------------------------------------------------------

    /**
     * Run a report and return the rows, the totals and everything that
     * qualifies them.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function run(array $config, ?Period $override = null): array
    {
        Permissions::assert($this->ctx, $this->auth, 'report.view');

        $config = $this->config($config);
        $period = $override ?? Period::fromConfig($config['period'], (string) $config['grain']);
        $sources = new Sources();

        $metricIds = $config['metrics'];
        $dimension = $config['dimension'];

        $batch = $this->query->metrics($period, $metricIds, $config['filters']);
        $sources->merge($batch['sources']);

        $columns = [];
        $rows = [];
        $warnings = [];
        $coverage = 'complete';

        if ($dimension !== null) {
            // A dimensional report: one row per entry, one column per metric.
            $columns[] = ['key' => 'label', 'label' => MetricCatalog::DIMENSIONS[$dimension] ?? ucfirst($dimension), 'type' => 'text'];

            $byKey = [];
            foreach ($metricIds as $metricId) {
                $definition = $this->definitionFor($metricId);
                $columns[] = [
                    'key'   => $metricId,
                    'label' => $definition?->label ?? $metricId,
                    'type'  => $this->columnType($definition?->unit ?? 'currency'),
                    'unit'  => $definition?->unit ?? 'currency',
                ];

                $answer = $this->query->breakdown($period, $metricId, $dimension, $config['filters']);
                $sources->merge($answer['sources']);

                if ($answer['result'] === null) {
                    $warnings[] = ($definition?->label ?? $metricId) . ' cannot be split by '
                        . strtolower(MetricCatalog::DIMENSIONS[$dimension] ?? $dimension) . ', so that column is empty.';
                    continue;
                }

                $payload = $answer['result']->jsonSerialize();
                if ($payload['coverage'] === MetricResult::COVERAGE_PARTIAL) {
                    $coverage = 'partial';
                }
                foreach ($payload['warnings'] as $warning) {
                    $warnings[] = ($definition?->label ?? $metricId) . ': ' . $warning;
                }

                foreach ($payload['breakdown'] as $entry) {
                    $key = (string) $entry['key'];
                    $byKey[$key] ??= ['label' => $entry['label']];
                    $byKey[$key][$metricId] = $entry['value'];
                    $byKey[$key][$metricId . '_formatted'] = $entry['formatted'];
                }
            }

            foreach (array_slice($byKey, 0, self::MAX_ROWS) as $row) {
                $rows[] = $row;
            }
            if (count($byKey) > self::MAX_ROWS) {
                $warnings[] = 'The report holds ' . count($byKey) . ' rows and the first ' . self::MAX_ROWS . ' are included. Narrow the filters to see the rest.';
                $coverage = 'partial';
            }
        } else {
            // A metric report: one row per metric, with its comparison.
            $columns = [
                ['key' => 'label', 'label' => 'Metric', 'type' => 'text'],
                ['key' => 'value', 'label' => 'This period', 'type' => 'currency'],
                ['key' => 'comparison', 'label' => 'Comparison period', 'type' => 'currency'],
                ['key' => 'change', 'label' => 'Change', 'type' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ];

            foreach ($metricIds as $metricId) {
                $result = $batch['results'][$metricId] ?? null;
                if ($result === null) {
                    continue;
                }
                $payload = $result->jsonSerialize();
                if ($payload['coverage'] === MetricResult::COVERAGE_PARTIAL) {
                    $coverage = 'partial';
                }
                foreach ($payload['warnings'] as $warning) {
                    $warnings[] = $payload['label'] . ': ' . $warning;
                }

                $rows[] = [
                    'label'               => $payload['label'],
                    'value'               => $payload['value'],
                    'value_formatted'     => $payload['formatted'],
                    'unit'                => $payload['unit'],
                    'comparison'          => $payload['comparison']['value'] ?? null,
                    'comparison_formatted' => $payload['comparison']['formatted'] ?? '—',
                    'change'              => $payload['comparison']['change_formatted'] ?? 'n/a',
                    'status'              => $payload['status'],
                    'definition'          => $payload['definition']['text'],
                    'formula_version'     => $payload['definition']['formula_version'],
                ];
            }
        }

        return [
            'title'      => (string) ($config['title'] ?? 'Report'),
            'period'     => $period->toArray(),
            'scope'      => $this->scopeLabels(),
            'filters'    => $config['filters'],
            'dimension'  => $dimension,
            'columns'    => $columns,
            'rows'       => $rows,
            'metrics'    => array_map(
                fn (string $id) => ($this->definitionFor($id))?->jsonSerialize(),
                $metricIds,
            ),
            'coverage'   => $coverage,
            'warnings'   => array_values(array_unique($warnings)),
            'sources'    => $sources->toArray(),
            'generated_at' => gmdate('c'),
            'generated_by' => $this->auth->displayName(),
        ];
    }

    /**
     * Export a report result as a file.
     *
     * @param array<string, mixed> $report the output of run()
     * @return array{filename:string, content_type:string, bytes:string}
     */
    public function export(array $report, string $format): array
    {
        Permissions::assert($this->ctx, $this->auth, 'report.export');

        if (!in_array($format, self::FORMATS, true)) {
            Http::validationFailed('Exports are PDF, CSV or XLSX.', ['field' => 'format']);
        }

        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', (string) $report['title']));
        $slug = trim($slug, '-') ?: 'insights-report';
        $filename = $slug . '-' . gmdate('Y-m-d') . '.' . $format;

        return match ($format) {
            'csv'  => ['filename' => $filename, 'content_type' => 'text/csv; charset=utf-8', 'bytes' => $this->csv($report)],
            'xlsx' => ['filename' => $filename, 'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'bytes' => $this->xlsx($report)],
            default => ['filename' => $filename, 'content_type' => 'application/pdf', 'bytes' => (new ReportRenderer())->render($report)],
        };
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $report */
    private function csv(array $report): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        // A BOM, so Excel opens a UTF-8 file as UTF-8 rather than mangling
        // every name with an accent in it and the rupee sign with it.
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($this->headerLines($report) as $line) {
            fputcsv($handle, [$this->cell($line)], ',', '"', '\\');
        }
        fputcsv($handle, [], ',', '"', '\\');

        fputcsv($handle, array_map(fn (array $c) => $this->cell((string) $c['label']), $report['columns']), ',', '"', '\\');

        foreach ($report['rows'] as $row) {
            $line = [];
            foreach ($report['columns'] as $column) {
                $key = (string) $column['key'];
                // Numbers go out as plain decimals, not as formatted text: a
                // rendering of "₹1,23,456.78" will not add up in any tool that
                // opens this file.
                $value = $row[$key] ?? null;
                $line[] = $column['type'] === 'text' ? $this->cell((string) ($value ?? '')) : (string) ($value ?? '');
            }
            fputcsv($handle, $line, ',', '"', '\\');
        }

        if ($report['warnings'] !== []) {
            fputcsv($handle, [], ',', '"', '\\');
            fputcsv($handle, [$this->cell('Warnings')], ',', '"', '\\');
            foreach ($report['warnings'] as $warning) {
                fputcsv($handle, [$this->cell((string) $warning)], ',', '"', '\\');
            }
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** @param array<string, mixed> $report */
    private function xlsx(array $report): string
    {
        $writer = new XlsxWriter((string) $report['title']);
        $writer->addTitle((string) $report['title']);

        foreach ($this->headerLines($report) as $line) {
            $writer->addRow([['value' => $line, 'type' => 'text']]);
        }
        $writer->addBlankRow();

        $writer->addHeader(array_map(static fn (array $c) => (string) $c['label'], $report['columns']));

        foreach ($report['rows'] as $row) {
            $cells = [];
            foreach ($report['columns'] as $column) {
                $key = (string) $column['key'];
                $cells[] = ['value' => $row[$key] ?? null, 'type' => (string) $column['type']];
            }
            $writer->addRow($cells);
        }

        if ($report['warnings'] !== []) {
            $writer->addBlankRow();
            $writer->addRow([['value' => 'Warnings', 'type' => 'text']]);
            foreach ($report['warnings'] as $warning) {
                $writer->addRow([['value' => (string) $warning, 'type' => 'text']]);
            }
        }

        return $writer->build();
    }

    /**
     * The provenance block every export carries.
     *
     * @param array<string, mixed> $report
     * @return list<string>
     */
    private function headerLines(array $report): array
    {
        $scope = $report['scope'];
        $lines = [
            'Company: ' . $scope['company'],
            'Branch: ' . $scope['branch'],
            'Financial year: ' . $scope['financial_year'],
            'Period: ' . $report['period']['label'] . ' (' . $report['period']['comparison_label'] . ')',
            'Generated: ' . $report['generated_at'] . ' by ' . $report['generated_by'],
            'Coverage: ' . ($report['coverage'] === 'partial'
                ? 'PARTIAL — see the warnings below before quoting these figures'
                : 'Complete for the sources that answered'),
        ];

        if ($report['filters'] !== []) {
            $filters = [];
            foreach ($report['filters'] as $field => $value) {
                $filters[] = $field . '=' . $value;
            }
            $lines[] = 'Filters: ' . implode(', ', $filters);
        }

        foreach ($report['sources'] as $source) {
            $lines[] = 'Source ' . $source['label'] . ': ' . $source['status_label']
                . ($source['fetched_at'] !== null ? ' (fetched ' . $source['fetched_at'] . ')' : '')
                . ($source['message'] !== null ? ' — ' . $source['message'] : '');
        }

        return $lines;
    }

    /**
     * Neutralise a leading character a spreadsheet would execute.
     *
     * Applies to the CSV path, where every cell is text as far as the file is
     * concerned. Numeric columns are written raw by the caller above, and are
     * not routed through here.
     */
    private function cell(string $value): string
    {
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/u', '', $value);

        if ($clean !== '' && str_contains("=+-@\t\r", $clean[0])) {
            return "'" . $clean;
        }

        return $clean;
    }

    /**
     * @param mixed $input
     * @return array{title:?string, metrics:list<string>, dimension:?string, grain:string, period:array<string,mixed>, filters:array<string,mixed>, formats:list<string>}
     */
    private function config(mixed $input): array
    {
        $input = is_array($input) ? $input : [];

        $metrics = [];
        foreach ((array) ($input['metrics'] ?? []) as $metricId) {
            if (!is_string($metricId)) {
                continue;
            }
            if (MetricCatalog::exists($metricId) || CustomMetrics::definition($this->ctx, $metricId) !== null) {
                $metrics[] = $metricId;
            }
        }
        if ($metrics === []) {
            $metrics = ['finance.net_revenue'];
        }

        $dimension = is_string($input['dimension'] ?? null) && isset(MetricCatalog::DIMENSIONS[$input['dimension']])
            ? (string) $input['dimension']
            : null;

        $formats = [];
        foreach ((array) ($input['formats'] ?? self::FORMATS) as $format) {
            if (is_string($format) && in_array($format, self::FORMATS, true)) {
                $formats[] = $format;
            }
        }

        // Read once. Writing this as a ternary over $input['grain'] reads the
        // key in the true branch, which warns when it was never set — and the
        // true branch is exactly the branch a missing key takes, because the
        // default it falls back to is itself a valid grain.
        $grain = (string) ($input['grain'] ?? 'month');

        return [
            'title'     => is_string($input['title'] ?? null) ? mb_substr($input['title'], 0, 160) : null,
            'metrics'   => array_slice(array_values(array_unique($metrics)), 0, 12),
            'dimension' => $dimension,
            'grain'     => in_array($grain, Period::GRAINS, true) ? $grain : 'month',
            'period'    => is_array($input['period'] ?? null) ? $input['period'] : [],
            'filters'   => \Aicountly\Api\Dashboards\WidgetSchema::filters($input['filters'] ?? null),
            'formats'   => $formats === [] ? self::FORMATS : $formats,
        ];
    }

    private function definitionFor(string $metricId): ?\Aicountly\Api\Metrics\MetricDefinition
    {
        return MetricCatalog::get($metricId) ?? CustomMetrics::definition($this->ctx, $metricId);
    }

    private function columnType(string $unit): string
    {
        return match ($unit) {
            'percent', 'percentage_points' => 'percent',
            'count'    => 'integer',
            'quantity' => 'decimal',
            'currency' => 'currency',
            default    => 'decimal',
        };
    }

    /**
     * Company, branch and financial-year NAMES, read live from Manage.
     *
     * An export headed "Company 41" is an export nobody can file. The ids are
     * ours; the names belong to Manage and are fetched on this request.
     *
     * @return array{company:string, branch:string, financial_year:string}
     */
    private function scopeLabels(): array
    {
        $labels = [
            'company'        => 'Company #' . $this->ctx->cmpId,
            'branch'         => $this->ctx->boId === 0 ? 'All branches' : 'Branch #' . $this->ctx->boId,
            'financial_year' => 'Financial year #' . $this->ctx->fyId,
        ];

        $result = (new ManageClient())->withSession($this->auth->sesKey())->companyInfo($this->ctx->cmpId);
        if (!($result['ok'] ?? false)) {
            return $labels;
        }

        $data = Payload::data($result);
        $company = is_array($data['company'] ?? null) ? $data['company'] : $data;

        $name = Payload::text($company, 'cmp_name') ?: Payload::text($company, 'company_name') ?: Payload::text($company, 'name');
        if ($name !== '') {
            $labels['company'] = $name;
        }

        foreach (Payload::rows($data, 'financial_years') as $year) {
            if ((int) ($year['fy_id'] ?? 0) === $this->ctx->fyId) {
                $from = Payload::text($year, 'fy_from') ?: Payload::text($year, 'start_date');
                $to = Payload::text($year, 'fy_to') ?: Payload::text($year, 'end_date');
                if ($from !== '' && $to !== '') {
                    $labels['financial_year'] = Format::date($from) . ' – ' . Format::date($to);
                }
                break;
            }
        }

        if ($this->ctx->boId > 0) {
            foreach (Payload::rows($data, 'branches') as $branch) {
                if ((int) ($branch['bo_id'] ?? 0) === $this->ctx->boId) {
                    $branchName = Payload::text($branch, 'bo_name') ?: Payload::text($branch, 'branch_name');
                    if ($branchName !== '') {
                        $labels['branch'] = $branchName;
                    }
                    break;
                }
            }
        }

        return $labels;
    }

    /** @return array<string, mixed>|null */
    private function row(string $publicId): ?array
    {
        if (!Ids::isValid($publicId, 'rep')) {
            return null;
        }

        $row = Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE cmp_id = :cmp AND public_id = :pid AND NOT is_archived',
            ['cmp' => $this->ctx->cmpId, 'pid' => $publicId],
        );

        if ($row === null) {
            return null;
        }

        $mine = (string) $row['owner_uuid'] === $this->auth->uuid;
        $shared = in_array((string) $row['visibility'], ['team', 'organisation'], true);

        if (!$mine && !$shared && !$this->auth->ownsCompany($this->ctx->cmpId) && !$this->auth->isService()) {
            return null;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id'          => $row['public_id'],
            'title'       => $row['title'],
            'description' => $row['description'],
            'config'      => Db::jsonColumn($row['config']),
            'visibility'  => $row['visibility'],
            'is_owner'    => (string) $row['owner_uuid'] === $this->auth->uuid,
            'created_at'  => $row['created_at'],
            'updated_at'  => $row['updated_at'],
        ];
    }
}
