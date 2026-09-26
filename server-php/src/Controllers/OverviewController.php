<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Analytics\OverviewService;
use Aicountly\Api\Dashboards\WidgetSchema;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Period;

/** The executive overview. One call, everything the page draws. */
final class OverviewController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'metric.view');

        $period = Period::fromRequest();
        $filters = WidgetSchema::filters(Http::objectParam('filters'));

        $service = new OverviewService($ctx, $auth, self::query($ctx, $auth));

        Http::data($service->build($period, $filters));
    }
}
