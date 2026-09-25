<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;
use Aicountly\Api\Reports\ReportService;
use Aicountly\Api\Support\Period;

/**
 * Saved reports, their previews and their files.
 *
 * The export path runs the SAME query the preview ran, through the same service
 * with the same session — so a download cannot contain a figure its requester
 * could not see on screen, and cannot quietly ignore a filter they set.
 */
final class ReportsController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'report.view');

        $params = Http::listParams(['updated_at'], 'updated_at');
        $service = new ReportService($ctx, $auth, self::query($ctx, $auth));
        $list = $service->index($params['limit'], $params['offset']);

        Http::list($list['rows'], $list['total'], $params['limit'], $params['offset'], [
            'can_manage' => Permissions::allows($ctx, $auth, 'report.manage'),
            'can_export' => Permissions::allows($ctx, $auth, 'report.export'),
            'formats'    => ReportService::FORMATS,
        ]);
    }

    public static function show(string $publicId): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'report.view');

        $service = new ReportService($ctx, $auth, self::query($ctx, $auth));
        $report = $service->find($publicId);

        if ($report === null) {
            Http::notFound('That report does not exist, or is not shared with you.');
        }

        Http::data($report);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReportService($ctx, $auth, self::query($ctx, $auth)))->save(Http::body()), 201);
    }

    public static function update(string $publicId): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReportService($ctx, $auth, self::query($ctx, $auth)))->save(Http::body(), $publicId));
    }

    public static function destroy(string $publicId): void
    {
        [$auth, $ctx] = self::enter();
        (new ReportService($ctx, $auth, self::query($ctx, $auth)))->delete($publicId);
        Http::data(['deleted' => true]);
    }

    /** Run an ad-hoc report definition, or a saved one, and return the rows. */
    public static function preview(): void
    {
        [$auth, $ctx] = self::enter();

        $service = new ReportService($ctx, $auth, self::query($ctx, $auth));
        $config = self::resolveConfig($ctx, $auth, $service);

        Http::data($service->run($config, Period::fromRequest((string) ($config['grain'] ?? 'month'))));
    }

    /** The same run, as a file. */
    public static function export(): void
    {
        [$auth, $ctx] = self::enter();

        $format = strtolower((string) (Http::param('format') ?? 'csv'));
        $service = new ReportService($ctx, $auth, self::query($ctx, $auth));
        $config = self::resolveConfig($ctx, $auth, $service);

        $report = $service->run($config, Period::fromRequest((string) ($config['grain'] ?? 'month')));
        $file = $service->export($report, $format);

        self::audit($ctx, $auth, $report, $format);

        if (PHP_SAPI === 'cli') {
            // Under CLI the bytes come back through the response so a test can
            // assert on the actual file rather than on a code path.
            Http::json(200, ['data' => [
                'filename'     => $file['filename'],
                'content_type' => $file['content_type'],
                'bytes'        => strlen($file['bytes']),
                'body'         => base64_encode($file['bytes']),
            ]]);
        }

        http_response_code(200);
        header('Content-Type: ' . $file['content_type']);
        header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
        header('Content-Length: ' . strlen($file['bytes']));
        // Short-lived and never cached by a shared proxy: an export can contain
        // a whole company's figures.
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Export-Coverage: ' . $report['coverage']);
        echo $file['bytes'];
        exit;
    }

    /**
     * A saved report's config, or one sent inline.
     *
     * @return array<string, mixed>
     */
    private static function resolveConfig(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth, ReportService $service): array
    {
        $publicId = Http::param('report_id');
        if (is_string($publicId) && $publicId !== '') {
            $saved = $service->find($publicId);
            if ($saved === null) {
                Http::notFound('That report does not exist, or is not shared with you.');
            }

            return $saved['config'] + ['title' => $saved['title']];
        }

        $body = Http::body();
        $config = is_array($body['config'] ?? null) ? $body['config'] : $body;

        return $config + ['title' => (string) (Http::param('title') ?? 'Insights report')];
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function audit(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth, array $report, string $format): void
    {
        try {
            Db::insert('insights_audit_events', [
                'cmp_id'      => $ctx->cmpId,
                'user_uuid'   => $auth->uuid,
                'action'      => 'report.exported',
                'object_type' => 'report',
                'object_id'   => null,
                // Counts and scope. Never a figure from the report.
                'detail'      => Db::json([
                    'format'   => $format,
                    'rows'     => count($report['rows']),
                    'coverage' => $report['coverage'],
                    'from'     => $report['period']['from'],
                    'to'       => $report['period']['to'],
                ]),
            ], 'event_id');
        } catch (\Throwable $e) {
            error_log('[insights][audit] export not recorded: ' . $e->getMessage());
        }
    }
}
