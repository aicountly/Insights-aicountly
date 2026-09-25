<?php

declare(strict_types=1);

namespace Aicountly\Api\Metrics;

/**
 * One governed metric: what it means, who owns it, and exactly where it is read
 * from.
 *
 * A metric with no verified binding does not belong in the catalogue. That is
 * the whole point of the type: an id here is a promise that some product, on
 * some endpoint that exists, answers this question — and `binding` names it so
 * the promise can be checked by reading the code rather than by clicking the
 * dashboard.
 *
 * `accountingBasis` is the double-count guard. Only `accrual` and `cash`
 * metrics carry accounting meaning and only Books and Inventory may own them.
 * Everything a Sales, POS or Billing screen shows is `operational`: an order
 * count, a pipeline value, a till session. A sale that POS rang up, Billing
 * invoiced and Books posted is ONE sale, and adding an operational figure to an
 * accounting one is the arithmetic error this product was built to prevent.
 */
final class MetricDefinition implements \JsonSerializable
{
    public const UNITS = ['currency', 'percent', 'percentage_points', 'count', 'days', 'ratio', 'quantity'];

    public const BASES = ['accrual', 'cash', 'balance', 'operational', 'derived'];

    /**
     * @param string       $id                stable id, `domain.name`
     * @param string       $label             what a person calls it
     * @param string       $definition        what it counts, in one sentence, including its GST / returns treatment
     * @param string       $owningProduct     books | inventory | sales | purchases | billing | pos | insights
     * @param string       $binding           the verified endpoint + field path it is read from
     * @param string       $measure           the field or expression in the source payload
     * @param string       $aggregation       sum | last | average | count | derived
     * @param string       $unit              one of UNITS
     * @param int          $precision         decimal places for display
     * @param list<string> $dimensions        dimensions this metric may be broken down by
     * @param list<string> $grains            date grains it is meaningful at
     * @param string       $accountingBasis   one of BASES
     * @param list<string> $sourcePermissions the permissions the OWNING product requires of the viewer
     * @param string       $betterWhen        'up' | 'down' | 'neutral'
     * @param array<string, mixed>|null $drilldown where a reader goes to see the records behind it
     * @param string       $formulaVersion    bumped whenever the meaning changes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $definition,
        public readonly string $owningProduct,
        public readonly string $binding,
        public readonly string $measure,
        public readonly string $aggregation,
        public readonly string $unit,
        public readonly int $precision,
        public readonly array $dimensions,
        public readonly array $grains,
        public readonly string $accountingBasis,
        public readonly array $sourcePermissions,
        public readonly string $betterWhen = 'up',
        public readonly ?array $drilldown = null,
        public readonly string $formulaVersion = '1.0.0',
        /** True when the metric is a point-in-time balance rather than a flow over a period. */
        public readonly bool $isBalance = false,
        /** Metric ids this one is computed from. Empty for a directly bound metric. */
        public readonly array $dependsOn = [],
    ) {
    }

    /**
     * How a change in this metric should be stated.
     *
     * A percentage metric changes by percentage POINTS. Saying a 40% margin
     * that became 44% "rose 10%" is true of the ratio and false of the margin,
     * and it is the single most common way a management report misleads.
     */
    public function comparisonKind(): string
    {
        return in_array($this->unit, ['percent', 'ratio'], true) ? 'percentage_points' : 'percent';
    }

    /** Whether this metric can be broken down by a dimension. */
    public function supportsDimension(string $dimension): bool
    {
        return in_array($dimension, $this->dimensions, true);
    }

    public function supportsGrain(string $grain): bool
    {
        return in_array($grain, $this->grains, true);
    }

    /** True when a value from this metric may be added to a value from the other. */
    public function isCompatibleWith(self $other): bool
    {
        if ($this->unit !== $other->unit) {
            return false;
        }

        // An accounting figure and an operational one measure different things
        // even when both are rupees. `derived` sits with whatever it derives
        // from, which the expression validator checks separately.
        $mixable = ['accrual', 'cash', 'balance'];
        if (in_array($this->accountingBasis, $mixable, true) !== in_array($other->accountingBasis, $mixable, true)) {
            return false;
        }

        return true;
    }

    /** The text a card shows when there is no value, which is never "0". */
    public function emptyText(string $status): string
    {
        return match ($status) {
            MetricResult::DENIED         => 'Not permitted',
            MetricResult::NOT_APPLICABLE => 'Not applicable',
            default                      => 'Unavailable',
        };
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id'                 => $this->id,
            'label'              => $this->label,
            'definition'         => $this->definition,
            'owning_product'     => $this->owningProduct,
            'binding'            => $this->binding,
            'measure'            => $this->measure,
            'aggregation'        => $this->aggregation,
            'unit'               => $this->unit,
            'precision'          => $this->precision,
            'dimensions'         => $this->dimensions,
            'grains'             => $this->grains,
            'accounting_basis'   => $this->accountingBasis,
            'source_permissions' => $this->sourcePermissions,
            'better_when'        => $this->betterWhen,
            'drilldown'          => $this->drilldown,
            'formula_version'    => $this->formulaVersion,
            'is_balance'         => $this->isBalance,
            'depends_on'         => $this->dependsOn,
            'comparison_kind'    => $this->comparisonKind(),
        ];
    }
}
