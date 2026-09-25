<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

use Aicountly\Api\Http;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The date range a query is answering for, what it is compared against, and at
 * what grain it is bucketed.
 *
 * The comparison period is the SAME NUMBER OF DAYS immediately before the
 * selected range. Comparing a 16-day month-to-date against a full previous
 * month is the most common way a dashboard reports a collapse that did not
 * happen.
 *
 * A PERCENTAGE CHANGE AND A PERCENTAGE-POINT CHANGE ARE DIFFERENT THINGS and
 * this class does not conflate them: it hands out the two windows, and the
 * metric's own unit decides which comparison is meaningful (see
 * Metrics\MetricDefinition::comparisonKind()).
 */
final class Period
{
    public const PRESETS = [
        'this_month', 'last_month', 'last_7_days', 'last_30_days', 'last_90_days',
        'this_quarter', 'last_quarter', 'this_year', 'financial_year', 'custom',
    ];

    public const GRAINS = ['day', 'week', 'month', 'quarter', 'year'];

    public const COMPARISONS = ['previous_period', 'previous_year', 'none'];

    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $compareFrom,
        public readonly ?string $compareTo,
        public readonly string $preset,
        public readonly string $comparisonMode,
        public readonly string $grain,
        public readonly string $timezone,
    ) {
    }

    public static function fromRequest(string $defaultGrain = 'month'): self
    {
        return self::build(
            (string) (Http::param('preset') ?? ''),
            self::dateParam('from'),
            self::dateParam('to'),
            (string) (Http::param('compare') ?? 'previous_period'),
            (string) (Http::param('grain') ?? $defaultGrain),
            (string) (Http::param('timezone') ?? ''),
        );
    }

    /**
     * A period from a widget or report configuration.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config, string $defaultGrain = 'month'): self
    {
        return self::build(
            (string) ($config['preset'] ?? ''),
            self::normaliseDate($config['from'] ?? null),
            self::normaliseDate($config['to'] ?? null),
            (string) ($config['compare'] ?? 'previous_period'),
            (string) ($config['grain'] ?? $defaultGrain),
            (string) ($config['timezone'] ?? ''),
        );
    }

    public static function forDates(string $from, string $to, string $grain = 'month', string $timezone = 'Asia/Kolkata'): self
    {
        return self::build('custom', $from, $to, 'previous_period', $grain, $timezone);
    }

    private static function build(
        string $preset,
        ?string $from,
        ?string $to,
        string $compare,
        string $grain,
        string $timezone,
    ): self {
        $timezone = self::validTimezone($timezone);
        $today = new DateTimeImmutable('now', new DateTimeZone($timezone));

        if ($preset === '' || !in_array($preset, self::PRESETS, true)) {
            $preset = ($from !== null || $to !== null) ? 'custom' : 'this_month';
        }

        if ($preset !== 'custom') {
            [$from, $to] = self::resolvePreset($preset, $today);
        } else {
            $from ??= $today->modify('first day of this month')->format('Y-m-d');
            $to ??= $today->format('Y-m-d');
        }

        // A range the wrong way round is a slip, not a reason to show nothing.
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        if (!in_array($compare, self::COMPARISONS, true)) {
            $compare = 'previous_period';
        }
        if (!in_array($grain, self::GRAINS, true)) {
            $grain = 'month';
        }

        [$compareFrom, $compareTo] = self::resolveComparison($compare, $from, $to, $timezone);

        return new self($from, $to, $compareFrom, $compareTo, $preset, $compare, $grain, $timezone);
    }

    /** @return array{0:?string, 1:?string} */
    private static function resolveComparison(string $mode, string $from, string $to, string $timezone): array
    {
        if ($mode === 'none') {
            return [null, null];
        }

        $zone = new DateTimeZone($timezone);
        $start = new DateTimeImmutable($from, $zone);
        $end = new DateTimeImmutable($to, $zone);

        if ($mode === 'previous_year') {
            return [
                $start->modify('-1 year')->format('Y-m-d'),
                $end->modify('-1 year')->format('Y-m-d'),
            ];
        }

        $days = (int) $start->diff($end)->days + 1;

        return [
            $start->modify('-' . $days . ' days')->format('Y-m-d'),
            $start->modify('-1 day')->format('Y-m-d'),
        ];
    }

    /** @return array{0:string, 1:string} */
    private static function resolvePreset(string $preset, DateTimeImmutable $today): array
    {
        $quarterStart = static fn (DateTimeImmutable $d): DateTimeImmutable => $d->setDate(
            (int) $d->format('Y'),
            (intdiv((int) $d->format('n') - 1, 3) * 3) + 1,
            1,
        );

        return match ($preset) {
            'last_month' => [
                $today->modify('first day of last month')->format('Y-m-d'),
                $today->modify('last day of last month')->format('Y-m-d'),
            ],
            'last_7_days'  => [$today->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')],
            'last_30_days' => [$today->modify('-29 days')->format('Y-m-d'), $today->format('Y-m-d')],
            'last_90_days' => [$today->modify('-89 days')->format('Y-m-d'), $today->format('Y-m-d')],
            'this_quarter' => [$quarterStart($today)->format('Y-m-d'), $today->format('Y-m-d')],
            'last_quarter' => [
                $quarterStart($today)->modify('-3 months')->format('Y-m-d'),
                $quarterStart($today)->modify('-1 day')->format('Y-m-d'),
            ],
            'this_year' => [$today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d'), $today->format('Y-m-d')],
            // The Indian financial year, 1 April to 31 March. It is the default
            // reporting year for every company in this fleet, and deriving it
            // from the calendar year is how a Q1 report ends up covering the
            // wrong three months.
            'financial_year' => self::financialYear($today),
            default          => [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d')],
        };
    }

    /** @return array{0:string, 1:string} */
    private static function financialYear(DateTimeImmutable $today): array
    {
        $year = (int) $today->format('Y');
        $startYear = (int) $today->format('n') >= 4 ? $year : $year - 1;

        return [
            $today->setDate($startYear, 4, 1)->format('Y-m-d'),
            min($today->format('Y-m-d'), $today->setDate($startYear + 1, 3, 31)->format('Y-m-d')),
        ];
    }

    private static function dateParam(string $name): ?string
    {
        return self::normaliseDate(Http::param($name));
    }

    private static function normaliseDate(mixed $raw): ?string
    {
        if (!is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }
        // Reject 2025-02-30 and friends: a date PHP would silently roll forward
        // shifts a whole report by a day without saying so.
        [$y, $m, $d] = array_map('intval', explode('-', $raw));

        return checkdate($m, $d, $y) ? $raw : null;
    }

    private static function validTimezone(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 'Asia/Kolkata';
        }
        try {
            new DateTimeZone($raw);

            return $raw;
        } catch (\Throwable) {
            return 'Asia/Kolkata';
        }
    }

    public function days(): int
    {
        $zone = new DateTimeZone($this->timezone);

        return (int) (new DateTimeImmutable($this->from, $zone))->diff(new DateTimeImmutable($this->to, $zone))->days + 1;
    }

    public function hasComparison(): bool
    {
        return $this->compareFrom !== null && $this->compareTo !== null;
    }

    /** The comparison window as its own Period, for a second pass over the adapters. */
    public function comparison(): ?self
    {
        if (!$this->hasComparison()) {
            return null;
        }

        return new self(
            (string) $this->compareFrom,
            (string) $this->compareTo,
            null,
            null,
            'custom',
            'none',
            $this->grain,
            $this->timezone,
        );
    }

    public function withGrain(string $grain): self
    {
        if (!in_array($grain, self::GRAINS, true)) {
            return $this;
        }

        return new self($this->from, $this->to, $this->compareFrom, $this->compareTo, $this->preset, $this->comparisonMode, $grain, $this->timezone);
    }

    /**
     * The buckets a trend over this period has, at this grain.
     *
     * Produced from the calendar rather than by dividing the range, so a month
     * bucket is a month and February is not 30 days long. Each bucket carries
     * the window it covers, which is what lets a caller say "this bucket is
     * incomplete" about the one the period ends inside.
     *
     * @return list<array{key:string, label:string, from:string, to:string, partial:bool}>
     */
    public function buckets(): array
    {
        $zone = new DateTimeZone($this->timezone);
        $start = new DateTimeImmutable($this->from, $zone);
        $end = new DateTimeImmutable($this->to, $zone);

        $out = [];
        $cursor = $this->bucketStart($start);
        $guard = 0;

        while ($cursor <= $end && $guard++ < 2000) {
            $bucketEnd = $this->bucketEnd($cursor);
            $from = max($cursor->format('Y-m-d'), $this->from);
            $to = min($bucketEnd->format('Y-m-d'), $this->to);

            $out[] = [
                'key'     => $cursor->format('Y-m-d'),
                'label'   => $this->bucketLabel($cursor),
                'from'    => $from,
                'to'      => $to,
                // True when the period clips the bucket: a month-to-date column
                // next to whole months is the classic false cliff on a chart.
                'partial' => $from !== $cursor->format('Y-m-d') || $to !== $bucketEnd->format('Y-m-d'),
            ];

            $cursor = $bucketEnd->modify('+1 day');
        }

        return $out;
    }

    private function bucketStart(DateTimeImmutable $date): DateTimeImmutable
    {
        return match ($this->grain) {
            'day'     => $date,
            'week'    => $date->modify('monday this week'),
            'quarter' => $date->setDate((int) $date->format('Y'), (intdiv((int) $date->format('n') - 1, 3) * 3) + 1, 1),
            'year'    => $date->setDate((int) $date->format('Y'), 1, 1),
            default   => $date->modify('first day of this month'),
        };
    }

    private function bucketEnd(DateTimeImmutable $start): DateTimeImmutable
    {
        return match ($this->grain) {
            'day'     => $start,
            'week'    => $start->modify('+6 days'),
            'quarter' => $start->modify('+3 months')->modify('-1 day'),
            'year'    => $start->modify('+1 year')->modify('-1 day'),
            default   => $start->modify('last day of this month'),
        };
    }

    private function bucketLabel(DateTimeImmutable $start): string
    {
        return match ($this->grain) {
            'day'     => $start->format('j M'),
            'week'    => 'Week of ' . $start->format('j M'),
            'quarter' => 'Q' . (intdiv((int) $start->format('n') - 1, 3) + 1) . ' ' . $start->format('Y'),
            'year'    => $start->format('Y'),
            default   => $start->format('M Y'),
        };
    }

    public function label(): string
    {
        return Format::date($this->from) . ' – ' . Format::date($this->to);
    }

    public function comparisonLabel(): string
    {
        if (!$this->hasComparison()) {
            return 'no comparison';
        }

        return 'vs ' . Format::date((string) $this->compareFrom) . ' – ' . Format::date((string) $this->compareTo);
    }

    /** @return array<string, string> */
    public function params(): array
    {
        return ['from' => $this->from, 'to' => $this->to];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from'             => $this->from,
            'to'               => $this->to,
            'preset'           => $this->preset,
            'grain'            => $this->grain,
            'label'            => $this->label(),
            'days'             => $this->days(),
            'timezone'         => $this->timezone,
            'comparison_mode'  => $this->comparisonMode,
            'compare_from'     => $this->compareFrom,
            'compare_to'       => $this->compareTo,
            'comparison_label' => $this->comparisonLabel(),
        ];
    }
}
