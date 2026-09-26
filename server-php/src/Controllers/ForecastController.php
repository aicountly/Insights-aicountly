<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Analytics\ForecastService;
use Aicountly\Api\Dashboards\WidgetSchema;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Decimal;
use Aicountly\Api\Support\Period;

/** Forecasts, always with their method and assumptions attached. */
final class ForecastController extends Controller
{
    public static function metric(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'forecast.view');

        $metricId = (string) (Http::param('metric') ?? 'finance.net_revenue');
        $period = Period::fromRequest();

        $service = new ForecastService($ctx, $auth, self::query($ctx, $auth));
        $answer = $service->forMetric(
            $metricId,
            $period,
            (string) (Http::param('method') ?? 'moving_average'),
            Http::intParam('horizon', 3) ?? 3,
            self::scenario(),
            WidgetSchema::filters(Http::objectParam('filters')),
        );

        Http::data($answer['payload'] + ['sources' => $answer['sources']->toArray(), 'methods' => ForecastService::METHODS]);
    }

    public static function cashFlow(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'forecast.view');

        $period = Period::fromRequest();

        $service = new ForecastService($ctx, $auth, self::query($ctx, $auth));
        $answer = $service->cashFlow(
            $period,
            (string) (Http::param('method') ?? 'moving_average'),
            Http::intParam('horizon', 3) ?? 3,
            self::scenario(),
            WidgetSchema::filters(Http::objectParam('filters')),
        );

        Http::data($answer['payload'] + ['sources' => $answer['sources']->toArray(), 'methods' => ForecastService::METHODS]);
    }

    /**
     * A user's own "what if" adjustment, clamped.
     *
     * Returned as an exact decimal and labelled a SCENARIO everywhere it
     * appears. It is not a prediction interval and it is not a confidence band,
     * and the payload says so beside it.
     */
    private static function scenario(): ?string
    {
        $raw = Http::param('scenario_percent');
        if ($raw === null || $raw === '') {
            return null;
        }

        $parsed = Decimal::parse($raw);
        if ($parsed === null) {
            return null;
        }
        if (Decimal::cmp($parsed, '-100') < 0) {
            return '-100';
        }
        if (Decimal::cmp($parsed, '100') > 0) {
            return '100';
        }

        return $parsed;
    }
}
