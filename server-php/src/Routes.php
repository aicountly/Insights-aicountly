<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Controllers\AccessController;
use Aicountly\Api\Controllers\AiController;
use Aicountly\Api\Controllers\AnomaliesController;
use Aicountly\Api\Controllers\DashboardsController;
use Aicountly\Api\Controllers\ForecastController;
use Aicountly\Api\Controllers\ManageController;
use Aicountly\Api\Controllers\MetricsController;
use Aicountly\Api\Controllers\OverviewController;
use Aicountly\Api\Controllers\QueryController;
use Aicountly\Api\Controllers\ReportsController;
use Aicountly\Api\Controllers\SettingsController;
use Aicountly\Api\Controllers\SourcesController;

/**
 * The Insights API.
 *
 * ROUTE ORDER MATTERS. The router takes the first pattern that matches on
 * segment count, so a literal segment that could be read as an id is registered
 * FIRST — `v1/dashboards/templates` before `v1/dashboards/{id}`, or the word
 * "templates" is looked up as a dashboard and answers 404.
 *
 * Everything under v1 is company-scoped and authenticated. The three exceptions
 * are marked, and they are the ones a caller needs BEFORE they have a company:
 * the session, the company list and one company's details.
 */
final class Routes
{
    public static function register(Router $router): void
    {
        // -------------------------------------------------------------------
        // Before a company is chosen. Authenticated, not company-scoped.
        // -------------------------------------------------------------------
        $router->get('v1/manage/companies', [ManageController::class, 'companies']);
        $router->get('v1/manage/companyinfo', [ManageController::class, 'companyInfo']);

        // -------------------------------------------------------------------
        // Session, preferences and settings
        // -------------------------------------------------------------------
        $router->get('v1/session', [SettingsController::class, 'session']);
        $router->get('v1/permissions', [SettingsController::class, 'permissionsCatalog']);
        $router->get('v1/preferences', [SettingsController::class, 'showPreferences']);
        $router->put('v1/preferences', [SettingsController::class, 'updatePreferences']);
        $router->get('v1/settings', [SettingsController::class, 'showCompanySettings']);
        $router->put('v1/settings', [SettingsController::class, 'updateCompanySettings']);

        // -------------------------------------------------------------------
        // Access — Insights' own permission profiles
        // -------------------------------------------------------------------
        $router->get('v1/access/profiles', [AccessController::class, 'profiles']);
        $router->post('v1/access/profiles', [AccessController::class, 'createProfile']);
        $router->put('v1/access/profiles/{id}', [AccessController::class, 'updateProfile']);
        $router->get('v1/access/members', [AccessController::class, 'members']);
        $router->put('v1/access/members/{uuid}', [AccessController::class, 'assign']);
        $router->delete('v1/access/members/{uuid}', [AccessController::class, 'unassign']);

        // -------------------------------------------------------------------
        // Overview
        // -------------------------------------------------------------------
        $router->get('v1/overview', [OverviewController::class, 'index']);

        // -------------------------------------------------------------------
        // Metrics and the custom KPI editor
        //
        // `validate` and `custom` are registered before `{metricKey}` would be
        // matched, for the reason in the class comment above.
        // -------------------------------------------------------------------
        $router->get('v1/metrics', [MetricsController::class, 'index']);
        $router->post('v1/metrics/validate', [MetricsController::class, 'validate']);
        $router->post('v1/metrics/custom', [MetricsController::class, 'saveCustom']);
        $router->put('v1/metrics/custom/{metricKey}', [MetricsController::class, 'updateCustom']);
        $router->delete('v1/metrics/custom/{metricKey}', [MetricsController::class, 'retireCustom']);

        // -------------------------------------------------------------------
        // Queries — what every chart and card is drawn from
        // -------------------------------------------------------------------
        $router->get('v1/query/metrics', [QueryController::class, 'metrics']);
        $router->post('v1/query/metrics', [QueryController::class, 'metrics']);
        $router->get('v1/query/series', [QueryController::class, 'series']);
        $router->get('v1/query/breakdown', [QueryController::class, 'breakdown']);
        $router->get('v1/query/drilldown', [QueryController::class, 'drilldown']);

        // -------------------------------------------------------------------
        // Data sources
        // -------------------------------------------------------------------
        $router->get('v1/sources', [SourcesController::class, 'index']);
        $router->get('v1/sources/{product}', [SourcesController::class, 'show']);

        // -------------------------------------------------------------------
        // Dashboards
        //
        // The literal three-segment paths come first; `v1/dashboards/{id}` is
        // last of that length.
        // -------------------------------------------------------------------
        $router->get('v1/dashboards', [DashboardsController::class, 'index']);
        $router->post('v1/dashboards', [DashboardsController::class, 'create']);
        $router->get('v1/dashboards/templates', [DashboardsController::class, 'templates']);
        $router->post('v1/dashboards/preview', [DashboardsController::class, 'preview']);
        $router->get('v1/dashboards/{id}', [DashboardsController::class, 'show']);
        $router->put('v1/dashboards/{id}', [DashboardsController::class, 'update']);
        $router->delete('v1/dashboards/{id}', [DashboardsController::class, 'destroy']);
        $router->get('v1/dashboards/{id}/data', [DashboardsController::class, 'data']);
        $router->post('v1/dashboards/{id}/publish', [DashboardsController::class, 'publish']);
        $router->post('v1/dashboards/{id}/duplicate', [DashboardsController::class, 'duplicate']);
        $router->post('v1/dashboards/{id}/favourite', [DashboardsController::class, 'favourite']);
        $router->get('v1/dashboards/{id}/versions', [DashboardsController::class, 'versions']);
        $router->post('v1/dashboards/{id}/versions/{revision}', [DashboardsController::class, 'restore']);
        $router->post('v1/dashboards/{id}/shares', [DashboardsController::class, 'shares']);
        $router->delete('v1/dashboards/{id}/shares/{shareId}', [DashboardsController::class, 'unshare']);

        // -------------------------------------------------------------------
        // Forecasts
        // -------------------------------------------------------------------
        $router->get('v1/forecast', [ForecastController::class, 'metric']);
        $router->get('v1/forecast/cash-flow', [ForecastController::class, 'cashFlow']);

        // -------------------------------------------------------------------
        // Anomalies
        // -------------------------------------------------------------------
        $router->get('v1/anomalies', [AnomaliesController::class, 'index']);
        $router->post('v1/anomalies/{fingerprint}/review', [AnomaliesController::class, 'review']);
        $router->post('v1/anomalies/{fingerprint}/notes', [AnomaliesController::class, 'note']);

        // -------------------------------------------------------------------
        // Reports and exports
        // -------------------------------------------------------------------
        $router->get('v1/reports', [ReportsController::class, 'index']);
        $router->post('v1/reports', [ReportsController::class, 'create']);
        $router->post('v1/reports/preview', [ReportsController::class, 'preview']);
        $router->post('v1/reports/export', [ReportsController::class, 'export']);
        $router->get('v1/reports/export', [ReportsController::class, 'export']);
        $router->get('v1/reports/{id}', [ReportsController::class, 'show']);
        $router->put('v1/reports/{id}', [ReportsController::class, 'update']);
        $router->delete('v1/reports/{id}', [ReportsController::class, 'destroy']);

        // -------------------------------------------------------------------
        // Ask Insights
        //
        // `ask` produces; `apply` commits. Nothing a model wrote reaches the
        // database between the two without somebody pressing Apply.
        // -------------------------------------------------------------------
        $router->get('v1/ai/status', [AiController::class, 'status']);
        $router->post('v1/ai/ask', [AiController::class, 'ask']);
        $router->post('v1/ai/proposals/{id}/apply', [AiController::class, 'apply']);
        $router->delete('v1/ai/proposals/{id}', [AiController::class, 'discard']);
    }
}
