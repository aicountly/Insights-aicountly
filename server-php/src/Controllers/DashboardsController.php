<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Adapters\AdapterRegistry;
use Aicountly\Api\Dashboards\DashboardService;
use Aicountly\Api\Dashboards\TemplateCatalog;
use Aicountly\Api\Dashboards\WidgetQueryService;
use Aicountly\Api\Dashboards\WidgetSchema;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Period;

/**
 * Dashboards: the library, the builder, and the data behind a board.
 *
 * Every read resolves the caller's access afresh, and the rendering endpoint
 * fetches with the CALLER's session — so a dashboard shared with somebody who
 * cannot see receivables in Books renders with its layout intact and that panel
 * saying so. That is the intended behaviour, not a degradation.
 */
final class DashboardsController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'dashboard.view');

        $params = Http::listParams(['updated_at', 'title', 'created_at'], 'updated_at');
        $scope = (string) (Http::param('scope') ?? 'all');
        if (!in_array($scope, ['all', 'mine', 'shared', 'team'], true)) {
            $scope = 'all';
        }

        $service = new DashboardService($ctx, $auth);
        $library = $service->library($scope, $params['q'], $params['limit'], $params['offset'], $params['sort'], $params['order']);

        Http::list($library['rows'], $library['total'], $params['limit'], $params['offset'], [
            'scope'      => $scope,
            'can_create' => Permissions::allows($ctx, $auth, 'dashboard.create'),
        ]);
    }

    public static function show(string $publicId): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'dashboard.view');

        $published = Http::boolParam('published', false);
        $dashboard = (new DashboardService($ctx, $auth))->find($publicId, $published);

        if ($dashboard === null) {
            Http::notFound('That dashboard does not exist, or is not shared with you.');
        }

        Http::data($dashboard);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter();

        $body = Http::body();

        // From a template: the widgets come from the catalogue, never from the
        // request, so a caller cannot smuggle configuration in under a template
        // name.
        $templateKey = is_string($body['template_key'] ?? null) ? $body['template_key'] : null;
        if ($templateKey !== null) {
            $template = TemplateCatalog::get($templateKey);
            if ($template === null) {
                Http::validationFailed('There is no template with that key.', ['field' => 'template_key']);
            }
            $body['widgets'] = $template['widgets'];
            $body['settings'] = $template['settings'];
            $body['title'] = is_string($body['title'] ?? null) && trim($body['title']) !== '' ? $body['title'] : $template['title'];
            $body['description'] = $template['description'];
        }

        Http::data((new DashboardService($ctx, $auth))->create($body), 201);
    }

    public static function update(string $publicId): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new DashboardService($ctx, $auth))->save($publicId, Http::body()));
    }

    public static function publish(string $publicId): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new DashboardService($ctx, $auth))->publish($publicId));
    }

    public static function duplicate(string $publicId): void
    {
        [$auth, $ctx] = self::enter();
        $title = Http::param('title');
        Http::data((new DashboardService($ctx, $auth))->duplicate($publicId, is_string($title) ? $title : null), 201);
    }

    public static function destroy(string $publicId): void
    {
        [$auth, $ctx] = self::enter();
        (new DashboardService($ctx, $auth))->delete($publicId);
        Http::data(['deleted' => true]);
    }

    public static function versions(string $publicId): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'dashboard.view');
        Http::data(['versions' => (new DashboardService($ctx, $auth))->versions($publicId)]);
    }

    public static function restore(string $publicId, string $revision): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new DashboardService($ctx, $auth))->restore($publicId, (int) $revision));
    }

    public static function shares(string $publicId): void
    {
        [$auth, $ctx] = self::enter();
        Http::data(['shares' => (new DashboardService($ctx, $auth))->share($publicId, Http::body())]);
    }

    public static function unshare(string $publicId, string $sharePublicId): void
    {
        [$auth, $ctx] = self::enter();
        Http::data(['shares' => (new DashboardService($ctx, $auth))->unshare($publicId, $sharePublicId)]);
    }

    public static function favourite(string $publicId): void
    {
        [$auth, $ctx] = self::enter();
        (new DashboardService($ctx, $auth))->favourite($publicId, Http::boolParam('favourite', true));
        Http::data(['ok' => true]);
    }

    /**
     * The figures for a saved dashboard.
     *
     * Separate from `show` on purpose: the layout is cheap and the data is not.
     * The builder loads the layout once, paints the frame, and asks for figures
     * — so dragging a widget never re-queries five products.
     */
    public static function data(string $publicId): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'dashboard.view');

        $service = new DashboardService($ctx, $auth);
        $dashboard = $service->find($publicId, Http::boolParam('published', false));

        if ($dashboard === null) {
            Http::notFound('That dashboard does not exist, or is not shared with you.');
        }

        // The period on the request wins over the saved one, so the filter bar
        // works without re-saving the board.
        $override = Http::param('preset') !== null || Http::param('from') !== null
            ? Period::fromRequest((string) ($dashboard['settings']['grain'] ?? 'month'))
            : null;

        $renderer = new WidgetQueryService($ctx, $auth, self::query($ctx, $auth));
        $rendered = $renderer->renderAll($dashboard['widgets'], $dashboard['settings'], $override);

        Http::data([
            'dashboard_id' => $dashboard['id'],
            'access'       => $dashboard['access'],
            'viewing'      => $dashboard['viewing'],
        ] + $rendered);
    }

    /**
     * Preview an unsaved board.
     *
     * The builder uses this for the live canvas: widgets are validated exactly
     * as they would be on save, so a preview that renders is a board that will
     * save, and one that will not save says why before anybody loses work.
     */
    public static function preview(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'dashboard.view');

        $body = Http::body();
        $widgets = is_array($body['widgets'] ?? null) ? $body['widgets'] : [];

        if (count($widgets) > WidgetSchema::MAX_WIDGETS) {
            Http::validationFailed('A dashboard holds up to ' . WidgetSchema::MAX_WIDGETS . ' widgets.', ['field' => 'widgets']);
        }

        $clean = [];
        $rejected = [];
        foreach (array_values($widgets) as $index => $widget) {
            if (!is_array($widget)) {
                continue;
            }
            try {
                $clean[] = WidgetSchema::widget($widget, $ctx) + ['id' => (string) ($widget['id'] ?? 'preview-' . $index)];
            } catch (\Aicountly\Api\Dashboards\WidgetSchemaError $e) {
                $rejected[] = [
                    'index'   => $index,
                    'id'      => $widget['id'] ?? null,
                    'field'   => $e->field,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $settings = WidgetSchema::dashboardSettings($body['settings'] ?? null);
        $renderer = new WidgetQueryService($ctx, $auth, self::query($ctx, $auth));
        $rendered = $renderer->renderAll($clean, $settings);

        Http::data($rendered + ['rejected' => $rejected]);
    }

    /**
     * The templates, with what each one needs, checked against this deployment.
     */
    public static function templates(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'dashboard.view');

        $registry = new AdapterRegistry($auth);

        $out = [];
        foreach (TemplateCatalog::all() as $template) {
            $out[] = [
                'key'          => $template['key'],
                'title'        => $template['title'],
                'description'  => $template['description'],
                'audience'     => $template['audience'],
                'widget_count' => count($template['widgets']),
                'settings'     => $template['settings'],
                // Checked before anybody clicks Create, so a template that will
                // half work says so first.
                'requirements' => TemplateCatalog::requirements($template, $registry, $ctx),
            ];
        }

        Http::data([
            'templates' => $out,
            'note'      => 'Templates carry configuration only. No template contains a sample figure; a new dashboard is empty until it asks your connected products.',
        ]);
    }
}
