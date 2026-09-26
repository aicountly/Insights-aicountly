<?php

declare(strict_types=1);

namespace Aicountly\Api\Analytics;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Metrics\MetricCatalog;
use Aicountly\Api\Metrics\QueryService;
use Aicountly\Api\Support\Decimal;
use Aicountly\Api\Support\Format;
use Aicountly\Api\Support\Period;
use Aicountly\Api\Support\Sources;

/**
 * Forecasts, with the method and its assumptions on the face of the answer.
 *
 * THREE BASELINE METHODS, and they are baselines on purpose. A moving average,
 * a least-squares trend and a seasonal naive are simple enough that the
 * assumption behind each one can be written in a sentence — which is the point:
 * a forecast somebody is going to plan cash against must be explainable, and an
 * unexplainable projection with a confident number on it is worse than no
 * projection at all.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO:
 *
 *   * No prediction intervals. A band around a projection means something
 *     specific in statistics, and neither a moving average nor an OLS line on
 *     six monthly points supports one honestly. What IS offered is a SCENARIO —
 *     the user's own "what if this moves by 10%" — and it is labelled a
 *     scenario everywhere it appears, never a confidence band.
 *
 *   * No confidence score. There is no defensible way to produce one here, and
 *     a number between 0 and 100 invites people to treat it as a probability.
 *
 *   * No forecasting of balances. A closing receivable is a level, not a flow;
 *     extrapolating one by fitting a line through three month-ends is arithmetic
 *     that looks like analysis.
 *
 * Where there is enough history, the method is BACKTESTED: the last few actual
 * buckets are withheld, the method is run on what is left, and the mean absolute
 * percentage error is reported. An error measure that a reader can see is the
 * difference between "the model says" and "this method was out by 18% last
 * quarter".
 */
final class ForecastService
{
    public const METHODS = ['moving_average', 'linear_trend', 'seasonal_naive'];

    /** Buckets of history before a projection is offered at all. */
    public const MIN_HISTORY = 4;

    /** Buckets before a backtest is meaningful. */
    public const MIN_FOR_BACKTEST = 8;

    public const MAX_HORIZON = 24;

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
        private readonly QueryService $query,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{payload: array<string, mixed>, sources: Sources}
     */
    public function forMetric(
        string $metricId,
        Period $period,
        string $method,
        int $horizon,
        ?string $scenarioPercent,
        array $filters = [],
    ): array {
        $definition = MetricCatalog::get($metricId);
        $sources = new Sources();

        if ($definition === null) {
            return ['payload' => $this->refuse('That metric is not in the catalogue.'), 'sources' => $sources];
        }

        if ($definition->isBalance) {
            return [
                'payload' => $this->refuse(
                    $definition->label . ' is a balance at a date, not a total over a period. Projecting one by fitting a line through past month-ends would look like analysis and would not be. Forecast the flows that move it instead — sales and collections.',
                ),
                'sources' => $sources,
            ];
        }

        if (!in_array($method, self::METHODS, true)) {
            $method = 'moving_average';
        }
        $horizon = max(1, min(self::MAX_HORIZON, $horizon));

        // History is read at the SAME grain the projection is produced at, over
        // a window long enough for the method to have something to work with.
        $grain = in_array($period->grain, ['month', 'quarter', 'week'], true) ? $period->grain : 'month';
        $history = $this->history($metricId, $period, $grain, $filters, $sources);

        if ($history['status'] !== 'ok') {
            return ['payload' => $history['payload'], 'sources' => $sources];
        }

        /** @var list<array{key:string, label:string, value:string}> $actuals */
        $actuals = $history['points'];
        $warnings = $history['warnings'];

        if (count($actuals) < self::MIN_HISTORY) {
            return [
                'payload' => $this->refuse(sprintf(
                    'A projection needs at least %d complete %ss of history and there are %d. Widen the period, or wait until there is more history.',
                    self::MIN_HISTORY,
                    $grain,
                    count($actuals),
                )),
                'sources' => $sources,
            ];
        }

        $values = array_map(static fn (array $p) => $p['value'], $actuals);
        $projection = $this->project($method, $values, $horizon, $grain);

        if ($projection === null) {
            return ['payload' => $this->refuse('This method cannot be applied to the history available.'), 'sources' => $sources];
        }

        $backtest = count($actuals) >= self::MIN_FOR_BACKTEST
            ? $this->backtest($method, $values, $grain)
            : null;

        $scenario = $this->scenario($projection, $scenarioPercent);

        $projected = [];
        $cursor = new \DateTimeImmutable($actuals[count($actuals) - 1]['key']);
        foreach ($projection as $index => $value) {
            $cursor = $this->advance($cursor, $grain);
            $projected[] = [
                'key'       => $cursor->format('Y-m-d'),
                'label'     => $this->label($cursor, $grain),
                'value'     => $value,
                'formatted' => Format::forUnit($value, $definition->unit, 'INR', $definition->precision),
                'scenario'  => $scenario[$index] ?? null,
                'scenario_formatted' => isset($scenario[$index])
                    ? Format::forUnit($scenario[$index], $definition->unit, 'INR', $definition->precision)
                    : null,
            ];
        }

        return [
            'payload' => [
                'status'  => 'ok',
                'metric'  => [
                    'id'    => $definition->id,
                    'label' => $definition->label,
                    'unit'  => $definition->unit,
                    'definition' => $definition->definition,
                ],
                'grain'   => $grain,
                'horizon' => $horizon,
                'method'  => [
                    'id'          => $method,
                    'label'       => $this->methodLabel($method),
                    'assumption'  => $this->assumption($method, $grain),
                ],
                'history' => array_map(static fn (array $p) => [
                    'key'       => $p['key'],
                    'label'     => $p['label'],
                    'value'     => $p['value'],
                    'formatted' => Format::forUnit($p['value'], $definition->unit, 'INR', $definition->precision),
                ], $actuals),
                'projection' => $projected,
                'scenario'   => $scenarioPercent === null ? null : [
                    'adjustment_percent' => $scenarioPercent,
                    'label'              => 'Scenario: every projected ' . $grain . ' moved by ' . Format::signed($scenarioPercent, 1),
                    'note'               => 'A scenario is an assumption you chose, not a prediction interval and not a confidence band.',
                ],
                'accuracy' => $backtest,
                'warnings' => array_values(array_unique(array_merge($warnings, $this->qualityWarnings($actuals, $backtest, $grain)))),
                'note'     => 'Projected from this company\'s own history through the connected products. It assumes nothing about orders not yet placed, and it changes nothing anywhere.',
            ],
            'sources' => $sources,
        ];
    }

    /**
     * A simple cash-flow view: opening balance, expected receipts, expected payments.
     *
     * Three separate lines rather than one net number, because that is how the
     * question is actually asked — "will I be able to pay in March" needs the
     * parts, not the difference.
     *
     * @param array<string, mixed> $filters
     * @return array{payload: array<string, mixed>, sources: Sources}
     */
    public function cashFlow(Period $period, string $method, int $horizon, ?string $scenarioPercent, array $filters = []): array
    {
        $sources = new Sources();

        $opening = $this->query->metrics($period, ['finance.cash_and_bank'], $filters, false);
        $sources->merge($opening['sources']);
        $openingResult = $opening['results']['finance.cash_and_bank'] ?? null;

        $receipts = $this->forMetric('finance.collections', $period, $method, $horizon, $scenarioPercent, $filters);
        $payments = $this->forMetric('finance.payments_made', $period, $method, $horizon, $scenarioPercent, $filters);
        $sources->merge($receipts['sources'])->merge($payments['sources']);

        $rows = [];
        $running = $openingResult?->numericValue();

        $receiptRows = $receipts['payload']['projection'] ?? [];
        $paymentRows = $payments['payload']['projection'] ?? [];
        $buckets = max(count($receiptRows), count($paymentRows));

        for ($i = 0; $i < $buckets; $i++) {
            $in = $receiptRows[$i]['value'] ?? null;
            $out = $paymentRows[$i]['value'] ?? null;

            // A closing balance needs all three. With any of them missing the
            // row says so rather than carrying the last known balance forward
            // as though nothing happened.
            $closing = null;
            if ($running !== null && $in !== null && $out !== null) {
                $closing = Decimal::sub(Decimal::add($running, $in), $out);
            }

            $rows[] = [
                'key'       => $receiptRows[$i]['key'] ?? ($paymentRows[$i]['key'] ?? ''),
                'label'     => $receiptRows[$i]['label'] ?? ($paymentRows[$i]['label'] ?? ''),
                'opening'   => $running,
                'opening_formatted'  => $running === null ? 'Unavailable' : Format::money($running),
                'receipts'  => $in,
                'receipts_formatted' => $in === null ? 'Unavailable' : Format::money($in),
                'payments'  => $out,
                'payments_formatted' => $out === null ? 'Unavailable' : Format::money($out),
                'closing'   => $closing,
                'closing_formatted'  => $closing === null ? 'Unavailable' : Format::money($closing),
            ];

            $running = $closing;
        }

        return [
            'payload' => [
                'status'  => $rows === [] ? 'unavailable' : 'ok',
                'opening' => $openingResult?->jsonSerialize(),
                'rows'    => $rows,
                'method'  => $receipts['payload']['method'] ?? null,
                'grain'   => $receipts['payload']['grain'] ?? $period->grain,
                'receipts_forecast' => $receipts['payload'],
                'payments_forecast' => $payments['payload'],
                'warnings' => array_values(array_unique(array_merge(
                    $receipts['payload']['warnings'] ?? [],
                    $payments['payload']['warnings'] ?? [],
                    $openingResult === null || !$openingResult->isAvailable()
                        ? ['The opening cash and bank balance could not be read, so the closing balances below are unavailable rather than assumed.']
                        : [],
                ))),
                'note' => 'Expected receipts and payments are projected from posted history. Invoices already raised and bills already entered are inside that history — they are not added again on top of it.',
            ],
            'sources' => $sources,
        ];
    }

    // -----------------------------------------------------------------------

    /**
     * Read the history for a metric at a grain.
     *
     * @param array<string, mixed> $filters
     * @return array{status:string, points?:list<array{key:string,label:string,value:string}>, warnings?:list<string>, payload?:array<string,mixed>}
     */
    private function history(string $metricId, Period $period, string $grain, array $filters, Sources $sources): array
    {
        // Enough buckets for the method AND for a backtest, read from a window
        // that ends where the selected period ends.
        $lookback = match ($grain) {
            'week'    => '-26 weeks',
            'quarter' => '-12 quarters',
            default   => '-24 months',
        };

        $from = (new \DateTimeImmutable($period->to))->modify($lookback)->format('Y-m-d');
        $window = Period::forDates($from, $period->to, $grain);

        $answer = $this->query->series($window, $metricId, $filters);
        $sources->merge($answer['sources']);

        if ($answer['result'] === null) {
            return [
                'status'  => 'refused',
                'payload' => $this->refuse(
                    (MetricCatalog::get($metricId)?->label ?? $metricId)
                        . ' is not reported over time by the product that owns it, so there is no history to project from.',
                ),
            ];
        }

        if (!$answer['result']->isAvailable()) {
            return [
                'status'  => 'refused',
                'payload' => $this->refuse((string) ($answer['result']->jsonSerialize()['message'] ?? 'The history could not be read.')),
            ];
        }

        $payload = $answer['result']->jsonSerialize();
        $points = [];
        $warnings = $payload['warnings'];

        foreach ($payload['series'] as $point) {
            // A partial bucket is the one the period ends inside. Feeding a
            // half month into a trend is how a forecast reports a collapse
            // that is just the calendar.
            if ($point['partial']) {
                $warnings[] = 'The most recent ' . $grain . ' is incomplete and has been left out of the projection.';
                continue;
            }
            if ($point['value'] === null) {
                continue;
            }
            $points[] = ['key' => $point['key'], 'label' => $point['label'], 'value' => (string) $point['value']];
        }

        return ['status' => 'ok', 'points' => $points, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @param list<string> $values
     * @return list<string>|null
     */
    private function project(string $method, array $values, int $horizon, string $grain): ?array
    {
        return match ($method) {
            'moving_average' => $this->movingAverage($values, $horizon),
            'linear_trend'   => $this->linearTrend($values, $horizon),
            'seasonal_naive' => $this->seasonalNaive($values, $horizon, $grain),
            default          => null,
        };
    }

    /**
     * The mean of the last three buckets, repeated.
     *
     * @param list<string> $values
     * @return list<string>
     */
    private function movingAverage(array $values, int $horizon): array
    {
        $window = array_slice($values, -3);
        $mean = Decimal::div(Decimal::sum($window), (string) count($window), 4) ?? '0';

        return array_fill(0, $horizon, $mean);
    }

    /**
     * Least squares through the history, extended.
     *
     * Computed with exact decimal arithmetic: the slope of a rupee series over
     * twenty-four months is a number a float would round in the fourth place,
     * and it is then multiplied by the horizon.
     *
     * @param list<string> $values
     * @return list<string>|null
     */
    private function linearTrend(array $values, int $horizon): ?array
    {
        $n = count($values);
        if ($n < 2) {
            return null;
        }

        $sumX = '0';
        $sumY = '0';
        $sumXy = '0';
        $sumXx = '0';

        foreach ($values as $index => $value) {
            $x = (string) ($index + 1);
            $sumX = Decimal::add($sumX, $x);
            $sumY = Decimal::add($sumY, $value);
            $sumXy = Decimal::add($sumXy, Decimal::mul($x, $value));
            $sumXx = Decimal::add($sumXx, Decimal::mul($x, $x));
        }

        $nStr = (string) $n;
        $numerator = Decimal::sub(Decimal::mul($nStr, $sumXy), Decimal::mul($sumX, $sumY));
        $denominator = Decimal::sub(Decimal::mul($nStr, $sumXx), Decimal::mul($sumX, $sumX));

        $slope = Decimal::div($numerator, $denominator, 6);
        if ($slope === null) {
            return null;
        }

        $intercept = Decimal::div(Decimal::sub($sumY, Decimal::mul($slope, $sumX)), $nStr, 6);
        if ($intercept === null) {
            return null;
        }

        $out = [];
        for ($step = 1; $step <= $horizon; $step++) {
            $x = (string) ($n + $step);
            $value = Decimal::add($intercept, Decimal::mul($slope, $x));
            // A projected negative sale is arithmetic, not a forecast. The
            // floor is stated in the assumption text so it is not a surprise.
            $out[] = Decimal::isNegative($value) ? '0' : Decimal::round($value, 4);
        }

        return $out;
    }

    /**
     * The same bucket a year ago, repeated forward.
     *
     * @param list<string> $values
     * @return list<string>|null
     */
    private function seasonalNaive(array $values, int $horizon, string $grain): ?array
    {
        $season = match ($grain) {
            'week'    => 52,
            'quarter' => 4,
            default   => 12,
        };

        if (count($values) < $season) {
            return null;
        }

        $out = [];
        for ($step = 0; $step < $horizon; $step++) {
            $index = count($values) - $season + ($step % $season);
            $out[] = $values[$index] ?? $values[count($values) - 1];
        }

        return $out;
    }

    /**
     * Withhold the last few buckets, project them, and report the error.
     *
     * Time-aware: the model never sees the buckets it is being scored on, which
     * is the difference between a backtest and a number that always looks good.
     *
     * @param list<string> $values
     * @return array{method:string, holdout:int, mape:?string, mape_formatted:string, note:string}|null
     */
    private function backtest(string $method, array $values, string $grain): ?array
    {
        $holdout = min(3, intdiv(count($values), 4));
        if ($holdout < 1) {
            return null;
        }

        $train = array_slice($values, 0, count($values) - $holdout);
        $actual = array_slice($values, -$holdout);
        $projected = $this->project($method, $train, $holdout, $grain);

        if ($projected === null) {
            return null;
        }

        $errors = [];
        foreach ($actual as $index => $observed) {
            $predicted = $projected[$index] ?? null;
            if ($predicted === null || Decimal::isZero($observed)) {
                // A percentage error against a zero actual is undefined, so
                // that bucket is left out rather than counted as 100%.
                continue;
            }
            $difference = Decimal::sub($predicted, $observed);
            $absolute = Decimal::isNegative($difference) ? Decimal::negate($difference) : $difference;
            $percent = Decimal::percentOf($absolute, Decimal::isNegative($observed) ? Decimal::negate($observed) : $observed, 2);
            if ($percent !== null) {
                $errors[] = $percent;
            }
        }

        if ($errors === []) {
            return null;
        }

        $mape = Decimal::div(Decimal::sum($errors), (string) count($errors), 2);

        return [
            'method'         => $method,
            'holdout'        => $holdout,
            'mape'           => $mape,
            'mape_formatted' => $mape === null ? 'n/a' : Format::percent($mape, 1),
            'note'           => sprintf(
                'Tested by hiding the last %d %s%s and projecting them from the rest. That is how far this method was out, on average, over those buckets — not a guarantee about the next ones.',
                $holdout,
                $grain,
                $holdout === 1 ? '' : 's',
            ),
        ];
    }

    /**
     * @param list<string> $projection
     * @return list<string>
     */
    private function scenario(array $projection, ?string $percent): array
    {
        if ($percent === null || Decimal::isZero($percent)) {
            return [];
        }

        $factor = Decimal::add('1', Decimal::div($percent, '100', 6) ?? '0');

        return array_map(static fn (string $value) => Decimal::round(Decimal::mul($value, $factor), 4), $projection);
    }

    /**
     * @param list<array{key:string,label:string,value:string}> $actuals
     * @param array<string, mixed>|null                          $backtest
     * @return list<string>
     */
    private function qualityWarnings(array $actuals, ?array $backtest, string $grain): array
    {
        $warnings = [];

        if (count($actuals) < self::MIN_FOR_BACKTEST) {
            $warnings[] = sprintf(
                'There are %d %ss of history, which is fewer than the %d needed to test how accurate this method has been. The projection is shown without an error measure.',
                count($actuals),
                $grain,
                self::MIN_FOR_BACKTEST,
            );
        }

        $zeros = 0;
        foreach ($actuals as $point) {
            if (Decimal::isZero($point['value'])) {
                $zeros++;
            }
        }
        if ($zeros > 0 && $zeros >= intdiv(count($actuals), 3)) {
            $warnings[] = $zeros . ' of the ' . count($actuals) . ' historical buckets are zero. A projection from a mostly empty history is a weak one.';
        }

        if ($backtest !== null && $backtest['mape'] !== null && Decimal::cmp((string) $backtest['mape'], '25') > 0) {
            $warnings[] = 'This method was out by ' . $backtest['mape_formatted'] . ' on average in the backtest, which is a lot. Treat the projection as a rough direction rather than a number to plan against.';
        }

        return $warnings;
    }

    /** @return array<string, mixed> */
    private function refuse(string $message): array
    {
        return [
            'status'     => 'unavailable',
            'message'    => $message,
            'history'    => [],
            'projection' => [],
            'warnings'   => [],
        ];
    }

    private function methodLabel(string $method): string
    {
        return match ($method) {
            'linear_trend'   => 'Straight-line trend',
            'seasonal_naive' => 'Same period last year',
            default          => 'Recent average',
        };
    }

    private function assumption(string $method, string $grain): string
    {
        return match ($method) {
            'linear_trend'   => 'Assumes the direction of the last two years continues at the same rate. Sensitive to a single unusual ' . $grain . '; a projected negative is shown as zero.',
            'seasonal_naive' => 'Assumes each ' . $grain . ' repeats what the same ' . $grain . ' did a year ago. Good where the business is seasonal and flat year on year; wrong where it is growing.',
            default          => 'Assumes the next ' . $grain . 's look like the average of the last three. Stable, and slow to notice a change of direction.',
        };
    }

    private function advance(\DateTimeImmutable $cursor, string $grain): \DateTimeImmutable
    {
        return match ($grain) {
            'week'    => $cursor->modify('+1 week'),
            'quarter' => $cursor->modify('+3 months'),
            default   => $cursor->modify('first day of next month'),
        };
    }

    private function label(\DateTimeImmutable $date, string $grain): string
    {
        return match ($grain) {
            'week'    => 'Week of ' . $date->format('j M'),
            'quarter' => 'Q' . (intdiv((int) $date->format('n') - 1, 3) + 1) . ' ' . $date->format('Y'),
            default   => $date->format('M Y'),
        };
    }
}
