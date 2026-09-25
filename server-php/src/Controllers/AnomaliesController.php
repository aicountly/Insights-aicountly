<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Analytics\AnomalyService;
use Aicountly\Api\Dashboards\WidgetSchema;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Period;

/** Business exceptions, detected live and reviewed by a person. */
final class AnomaliesController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'anomaly.view');

        $period = Period::fromRequest();
        $service = new AnomalyService($ctx, $auth, self::query($ctx, $auth));

        $result = $service->detect(
            $period,
            WidgetSchema::filters(Http::objectParam('filters')),
            Http::boolParam('include_reviewed', false),
        );

        Http::data($result + [
            'rules'      => AnomalyService::RULES,
            'can_review' => Permissions::allows($ctx, $auth, 'anomaly.review'),
            'note'       => 'Exceptions are found from figures fetched on this request. One that was fixed an hour ago is simply not here.',
        ]);
    }

    public static function review(string $fingerprint): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'anomaly.review');

        $service = new AnomalyService($ctx, $auth, self::query($ctx, $auth));

        Http::data($service->review($fingerprint, Http::body()));
    }

    public static function note(string $fingerprint): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'anomaly.review');

        $service = new AnomalyService($ctx, $auth, self::query($ctx, $auth));

        Http::data($service->addNote($fingerprint, (string) (Http::param('note') ?? '')));
    }
}
