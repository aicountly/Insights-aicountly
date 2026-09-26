<?php

declare(strict_types=1);

namespace Aicountly\Api\Metrics;

use Aicountly\Api\Context;
use Aicountly\Api\Support\Decimal;
use Aicountly\Api\Support\Format;
use Aicountly\Api\Support\Period;

/**
 * One metric, answered.
 *
 * EVERY FIGURE THIS PRODUCT SHOWS COMES BACK IN THIS SHAPE, and it carries its
 * own provenance for one reason: a number on a dashboard is only as good as the
 * reader's ability to find out where it came from and when. So a result says
 * which product answered, under which contract, what scope and period it covers,
 * when it was fetched, how fresh the source said it was, whether the coverage
 * was complete, and anything that qualifies it.
 *
 * THE STATUSES ARE NOT INTERCHANGEABLE, and collapsing them is how a dashboard
 * lies:
 *   available       we asked and this is the answer
 *   partial         we asked, this is part of the answer, and `coverage` says so
 *   unavailable     we could not ask, or the source could not answer
 *   denied          the source refused this viewer — not an error, an answer
 *   not_applicable  the calculation has no meaning here (a zero denominator)
 *
 * `unavailable` is NEVER rendered as zero. A payables figure that cannot be
 * fetched is not "nothing owed".
 */
final class MetricResult implements \JsonSerializable
{
    public const AVAILABLE      = 'available';
    public const PARTIAL        = 'partial';
    public const UNAVAILABLE    = 'unavailable';
    public const DENIED         = 'denied';
    public const NOT_APPLICABLE = 'not_applicable';

    public const COVERAGE_COMPLETE = 'complete';
    public const COVERAGE_PARTIAL  = 'partial';
    public const COVERAGE_UNKNOWN  = 'unknown';

    /** @var list<array{product:string, contract:string, endpoint:string}> */
    private array $provenance = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<array{key:string, label:string, value:?string, formatted:string, partial:bool}> */
    private array $series = [];

    /** @var list<array{key:string, label:string, value:?string, formatted:string, link:?array<string,mixed>}> */
    private array $breakdown = [];

    private ?string $comparisonValue = null;
    private ?string $change = null;
    private string $changeKind = 'percent';
    private ?string $sourceAsOf = null;
    private string $coverage = self::COVERAGE_COMPLETE;
    private string $fetchedAt;

    private function __construct(
        public readonly MetricDefinition $definition,
        public readonly string $status,
        public readonly ?string $value,
        public readonly Context $ctx,
        public readonly Period $period,
        public readonly string $currency,
        public readonly ?string $message,
    ) {
        $this->fetchedAt = gmdate('c');
    }

    public static function available(MetricDefinition $definition, string $value, Context $ctx, Period $period, string $currency = 'INR'): self
    {
        return new self($definition, self::AVAILABLE, $value, $ctx, $period, $currency, null);
    }

    public static function unavailable(MetricDefinition $definition, Context $ctx, Period $period, string $message, string $currency = 'INR'): self
    {
        $result = new self($definition, self::UNAVAILABLE, null, $ctx, $period, $currency, $message);
        $result->coverage = self::COVERAGE_UNKNOWN;

        return $result;
    }

    public static function denied(MetricDefinition $definition, Context $ctx, Period $period, string $message, string $currency = 'INR'): self
    {
        $result = new self($definition, self::DENIED, null, $ctx, $period, $currency, $message);
        $result->coverage = self::COVERAGE_UNKNOWN;

        return $result;
    }

    public static function notApplicable(MetricDefinition $definition, Context $ctx, Period $period, string $message, string $currency = 'INR'): self
    {
        return new self($definition, self::NOT_APPLICABLE, null, $ctx, $period, $currency, $message);
    }

    public function withProvenance(string $product, string $contract, string $endpoint): self
    {
        $this->provenance[] = ['product' => $product, 'contract' => $contract, 'endpoint' => $endpoint];

        return $this;
    }

    public function withWarning(string $warning): self
    {
        if (!in_array($warning, $this->warnings, true)) {
            $this->warnings[] = $warning;
        }

        return $this;
    }

    /**
     * Mark the answer as covering less than it was asked for.
     *
     * A first page of records is not a complete total, and a dashboard that
     * presents one as though it were is worse than one that shows nothing.
     */
    public function withPartialCoverage(string $why): self
    {
        $this->coverage = self::COVERAGE_PARTIAL;

        return $this->withWarning($why);
    }

    /** When the SOURCE says its answer was current. Not when we fetched it. */
    public function withSourceAsOf(?string $isoTimestamp): self
    {
        $this->sourceAsOf = $isoTimestamp;

        return $this;
    }

    /**
     * The comparison window's value, and the change between them.
     *
     * The KIND of change is decided by the metric, not here: a percentage
     * metric moving from 40% to 44% has risen 4 percentage POINTS and 10
     * percent, and reporting the wrong one is a factual error, not a rounding
     * preference.
     */
    public function withComparison(?string $previousValue): self
    {
        $this->comparisonValue = $previousValue;

        if ($previousValue === null || $this->value === null) {
            $this->change = null;

            return $this;
        }

        $this->changeKind = $this->definition->comparisonKind();
        $this->change = $this->changeKind === 'percentage_points'
            ? Decimal::sub($this->value, $previousValue)
            : Decimal::percentChange($previousValue, $this->value, 1);

        if ($this->change === null && $this->changeKind === 'percent') {
            $this->withWarning('There is no comparable figure for the previous period, so the change cannot be stated.');
        }

        return $this;
    }

    /** @param list<array{key:string, label:string, value:?string, partial?:bool}> $points */
    public function withSeries(array $points): self
    {
        $this->series = [];
        foreach ($points as $point) {
            $this->series[] = [
                'key'       => (string) $point['key'],
                'label'     => (string) $point['label'],
                'value'     => $point['value'],
                'formatted' => $this->format($point['value']),
                'partial'   => (bool) ($point['partial'] ?? false),
            ];
        }

        return $this;
    }

    /** @param list<array{key:string, label:string, value:?string, link?:?array<string,mixed>}> $rows */
    public function withBreakdown(array $rows): self
    {
        $this->breakdown = [];
        foreach ($rows as $row) {
            $this->breakdown[] = [
                'key'       => (string) $row['key'],
                'label'     => (string) $row['label'],
                'value'     => $row['value'],
                'formatted' => $this->format($row['value']),
                'link'      => $row['link'] ?? null,
            ];
        }

        return $this;
    }

    public function isAvailable(): bool
    {
        return $this->status === self::AVAILABLE || $this->status === self::PARTIAL;
    }

    /** The exact value, or null. Never a zero substituted for an absence. */
    public function numericValue(): ?string
    {
        return $this->isAvailable() ? $this->value : null;
    }

    public function format(?string $value): string
    {
        return Format::forUnit($value, $this->definition->unit, $this->currency, $this->definition->precision);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $status = $this->status;
        if ($status === self::AVAILABLE && $this->coverage === self::COVERAGE_PARTIAL) {
            $status = self::PARTIAL;
        }

        return [
            'metric_id'  => $this->definition->id,
            'label'      => $this->definition->label,
            'status'     => $status,
            'value'      => $this->value,
            'formatted'  => $this->value === null ? $this->definition->emptyText($status) : $this->format($this->value),
            'unit'       => $this->definition->unit,
            'currency'   => $this->definition->unit === 'currency' ? $this->currency : null,
            'precision'  => $this->definition->precision,
            'scope'      => [
                'company_id'        => $this->ctx->cmpId,
                'branch_id'         => $this->ctx->boId,
                'financial_year_id' => $this->ctx->fyId,
            ],
            'period'     => $this->period->toArray(),
            'definition' => [
                'text'            => $this->definition->definition,
                'owner'           => $this->definition->owningProduct,
                'accounting_basis' => $this->definition->accountingBasis,
                'formula_version' => $this->definition->formulaVersion,
            ],
            'provenance'   => $this->provenance,
            'fetched_at'   => $this->fetchedAt,
            'source_as_of' => $this->sourceAsOf,
            'coverage'     => $this->coverage,
            'warnings'     => $this->warnings,
            'message'      => $this->message,
            'comparison'   => $this->comparisonValue === null ? null : [
                'value'      => $this->comparisonValue,
                'formatted'  => $this->format($this->comparisonValue),
                'change'     => $this->change,
                'change_kind' => $this->changeKind,
                'change_formatted' => $this->change === null
                    ? 'n/a'
                    : Format::signed($this->change, 1, $this->changeKind === 'percentage_points'),
                'direction'  => $this->change === null
                    ? 'unknown'
                    : (Decimal::isZero($this->change) ? 'flat' : (Decimal::isNegative($this->change) ? 'down' : 'up')),
                // Which way is good depends on the metric: overdue receivables
                // falling is a win, revenue falling is not.
                'better_when' => $this->definition->betterWhen,
            ],
            'series'    => $this->series,
            'breakdown' => $this->breakdown,
            'drilldown' => $this->definition->drilldown,
        ];
    }
}
