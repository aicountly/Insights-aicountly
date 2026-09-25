<?php

declare(strict_types=1);

namespace Aicountly\Api\Analytics;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Metrics\MetricCatalog;
use Aicountly\Api\Metrics\QueryService;
use Aicountly\Api\Support\Decimal;
use Aicountly\Api\Support\Format;
use Aicountly\Api\Support\Ids;
use Aicountly\Api\Support\Period;
use Aicountly\Api\Support\Sources;

/**
 * Business exceptions, found live and reviewed by a person.
 *
 * DETECTED FROM LIVE FIGURES, EVERY TIME. There is no anomaly table holding
 * yesterday's findings, and no cron that walks the ledger overnight. The rules
 * below run against metrics fetched on the request that drew the screen, which
 * means an exception somebody fixed an hour ago is simply not there any more.
 *
 * WHAT IS STORED IS THE REVIEW, NOT THE EXCEPTION: acknowledged, dismissed with
 * a reason, reopened — keyed by a fingerprint of the rule, its subject and the
 * period. No transaction is copied, no amount is written down.
 *
 * NOTHING HERE IS CALLED FRAUD. Every rule states its baseline and its
 * evidence, and the severity is derived from the size of the deviation rather
 * than asserted. "Spending on this head is four times its recent average" is a
 * fact somebody can check; "suspicious payment" is an accusation this product
 * has no standing to make.
 */
final class AnomalyService
{
    public const TABLE = 'insights_anomaly_reviews';
    public const NOTES = 'insights_anomaly_notes';

    /**
     * The rules, and what each one is actually claiming.
     *
     * @var array<string, array{label:string, metric:string, baseline:string, description:string}>
     */
    public const RULES = [
        'revenue_drop' => [
            'label'       => 'Sales fell sharply',
            'metric'      => 'finance.net_revenue',
            'baseline'    => 'the comparison period',
            'description' => 'Net sales are materially below the equivalent previous period.',
        ],
        'collections_drop' => [
            'label'       => 'Collections fell sharply',
            'metric'      => 'finance.collections',
            'baseline'    => 'the comparison period',
            'description' => 'Money received is materially below the equivalent previous period, which can happen even while sales hold up.',
        ],
        'expense_spike' => [
            'label'       => 'Spending rose sharply',
            'metric'      => 'finance.expense',
            'baseline'    => 'the comparison period',
            'description' => 'Total expenses are materially above the equivalent previous period.',
        ],
        'overdue_concentration' => [
            'label'       => 'Overdue receivables concentrated',
            'metric'      => 'finance.overdue_share',
            'baseline'    => 'a threshold of 25% of the total owed',
            'description' => 'A large share of what customers owe is already past its due date.',
        ],
        'ageing_stock' => [
            'label'       => 'Stock is ageing',
            'metric'      => 'inventory.ageing_value',
            'baseline'    => 'a threshold of 20% of stock value held over 90 days',
            'description' => 'A material share of stock value has been on hand for a long time.',
        ],
        'single_customer_exposure' => [
            'label'       => 'Sales concentrated in one customer',
            'metric'      => 'finance.net_revenue',
            'baseline'    => 'a threshold of 40% of the period\'s sales',
            'description' => 'One customer accounts for a large share of the period\'s sales, which is a commercial risk rather than an error.',
        ],
    ];

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
        private readonly QueryService $query,
    ) {
    }

    /**
     * Run every rule and merge in each exception's review state.
     *
     * @param array<string, mixed> $filters
     * @return array{exceptions: list<array<string, mixed>>, sources: list<array<string, mixed>>, evaluated: list<string>, period: array<string, mixed>}
     */
    public function detect(Period $period, array $filters = [], bool $includeReviewed = false): array
    {
        $sources = new Sources();

        $metricIds = array_values(array_unique(array_map(
            static fn (array $rule) => $rule['metric'],
            self::RULES,
        )));

        $batch = $this->query->metrics($period, $metricIds, $filters);
        $sources->merge($batch['sources']);

        $found = [];
        $evaluated = [];

        foreach (self::RULES as $ruleId => $rule) {
            $result = $batch['results'][$rule['metric']] ?? null;
            if ($result === null || !$result->isAvailable()) {
                // A rule whose input could not be read did not find nothing —
                // it did not run. Saying so beats a clean screen that means
                // "we could not look".
                continue;
            }
            $evaluated[] = $ruleId;

            foreach ($this->apply($ruleId, $rule, $result, $period, $filters, $sources) as $exception) {
                $found[] = $exception;
            }
        }

        $reviews = $this->reviews(array_map(static fn (array $e) => $e['fingerprint'], $found));

        $out = [];
        foreach ($found as $exception) {
            $review = $reviews[$exception['fingerprint']] ?? null;
            $status = $review['status'] ?? 'open';

            if (!$includeReviewed && $status !== 'open') {
                continue;
            }

            $exception['review'] = [
                'status'      => $status,
                'reason'      => (string) ($review['reason'] ?? ''),
                'reviewed_by' => $review['reviewed_by'] ?? null,
                'reviewed_at' => $review['reviewed_at'] ?? null,
                'id'          => $review['public_id'] ?? null,
            ];
            $out[] = $exception;
        }

        // Most severe first, then largest deviation.
        usort($out, static function (array $a, array $b): int {
            $rank = ['high' => 3, 'medium' => 2, 'low' => 1];
            $bySeverity = ($rank[$b['severity']] ?? 0) <=> ($rank[$a['severity']] ?? 0);

            return $bySeverity !== 0 ? $bySeverity : Decimal::cmp((string) $b['deviation'], (string) $a['deviation']);
        });

        return [
            'exceptions' => $out,
            'sources'    => $sources->toArray(),
            'evaluated'  => $evaluated,
            'period'     => $period->toArray(),
        ];
    }

    /**
     * @param array{label:string, metric:string, baseline:string, description:string} $rule
     * @param array<string, mixed>                                                    $filters
     * @return list<array<string, mixed>>
     */
    private function apply(string $ruleId, array $rule, \Aicountly\Api\Metrics\MetricResult $result, Period $period, array $filters, Sources $sources): array
    {
        $payload = $result->jsonSerialize();
        $definition = MetricCatalog::require($rule['metric']);

        return match ($ruleId) {
            'revenue_drop', 'collections_drop' => $this->changeRule($ruleId, $rule, $payload, $definition, $period, 'down', '15'),
            'expense_spike'                    => $this->changeRule($ruleId, $rule, $payload, $definition, $period, 'up', '25'),
            'overdue_concentration'            => $this->thresholdRule($ruleId, $rule, $payload, $definition, $period, '25', 'above'),
            'ageing_stock'                     => $this->ageingRule($ruleId, $rule, $payload, $definition, $period),
            'single_customer_exposure'         => $this->concentrationRule($ruleId, $rule, $definition, $period, $filters, $sources),
            default                            => [],
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function changeRule(string $ruleId, array $rule, array $payload, \Aicountly\Api\Metrics\MetricDefinition $definition, Period $period, string $direction, string $threshold): array
    {
        $comparison = $payload['comparison'] ?? null;
        if (!is_array($comparison) || $comparison['change'] === null) {
            return [];
        }

        $change = (string) $comparison['change'];
        $magnitude = Decimal::isNegative($change) ? Decimal::negate($change) : $change;

        $wrongWay = $direction === 'down' ? !Decimal::isNegative($change) : Decimal::isNegative($change);
        if ($wrongWay || Decimal::cmp($magnitude, $threshold) < 0) {
            return [];
        }

        return [[
            'fingerprint' => $this->fingerprint($ruleId, $definition->id, $period),
            'rule_id'     => $ruleId,
            'rule'        => $rule['label'],
            'description' => $rule['description'],
            'subject'     => $definition->label,
            'severity'    => $this->severity($magnitude, $threshold),
            'severity_reason' => sprintf(
                '%s %s %s against %s, and the rule fires at %s%%. Severity follows the size of the move, nothing else.',
                $definition->label,
                $direction === 'down' ? 'fell' : 'rose',
                Format::percent($magnitude, 1),
                $rule['baseline'],
                $threshold,
            ),
            'deviation'   => $magnitude,
            'evidence'    => [
                [
                    'label'     => 'This period',
                    'value'     => $payload['value'],
                    'formatted' => $payload['formatted'],
                ],
                [
                    'label'     => 'Comparison period',
                    'value'     => $comparison['value'],
                    'formatted' => $comparison['formatted'],
                ],
                [
                    'label'     => 'Change',
                    'value'     => $change,
                    'formatted' => $comparison['change_formatted'],
                ],
            ],
            'metric'      => ['id' => $definition->id, 'label' => $definition->label, 'definition' => $definition->definition],
            'period'      => $period->toArray(),
            'drilldown'   => $payload['drilldown'] ?? null,
        ]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function thresholdRule(string $ruleId, array $rule, array $payload, \Aicountly\Api\Metrics\MetricDefinition $definition, Period $period, string $threshold, string $side): array
    {
        $value = $payload['value'];
        if ($value === null) {
            return [];
        }

        $breached = $side === 'above'
            ? Decimal::cmp((string) $value, $threshold) > 0
            : Decimal::cmp((string) $value, $threshold) < 0;

        if (!$breached) {
            return [];
        }

        $deviation = Decimal::isNegative(Decimal::sub((string) $value, $threshold))
            ? Decimal::negate(Decimal::sub((string) $value, $threshold))
            : Decimal::sub((string) $value, $threshold);

        return [[
            'fingerprint' => $this->fingerprint($ruleId, $definition->id, $period),
            'rule_id'     => $ruleId,
            'rule'        => $rule['label'],
            'description' => $rule['description'],
            'subject'     => $definition->label,
            'severity'    => $this->severity($deviation, '10'),
            'severity_reason' => sprintf(
                '%s is %s against %s. Severity follows how far past the threshold it is.',
                $definition->label,
                $payload['formatted'],
                $rule['baseline'],
            ),
            'deviation'   => $deviation,
            'evidence'    => [
                ['label' => $definition->label, 'value' => $value, 'formatted' => $payload['formatted']],
                ['label' => 'Threshold', 'value' => $threshold, 'formatted' => Format::percent($threshold, 0)],
            ],
            'metric'      => ['id' => $definition->id, 'label' => $definition->label, 'definition' => $definition->definition],
            'period'      => $period->toArray(),
            'drilldown'   => $payload['drilldown'] ?? null,
        ]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function ageingRule(string $ruleId, array $rule, array $payload, \Aicountly\Api\Metrics\MetricDefinition $definition, Period $period): array
    {
        $total = $payload['value'];
        if ($total === null || Decimal::isZero((string) $total)) {
            return [];
        }

        // The buckets a source names "over 90", "180+", "old" and so on. Matched
        // on the number in the label rather than on a bucket key we invented,
        // because the source names its own buckets.
        $old = '0';
        $oldLabels = [];
        foreach ($payload['breakdown'] as $row) {
            $label = strtolower((string) $row['label']);
            if (preg_match('/(\d{2,3})/', $label, $m) === 1 && (int) $m[1] >= 90) {
                $old = Decimal::add($old, (string) ($row['value'] ?? '0'));
                $oldLabels[] = (string) $row['label'];
            }
        }

        if (Decimal::isZero($old)) {
            return [];
        }

        $share = Decimal::percentOf($old, (string) $total, 1);
        if ($share === null || Decimal::cmp($share, '20') < 0) {
            return [];
        }

        return [[
            'fingerprint' => $this->fingerprint($ruleId, $definition->id, $period),
            'rule_id'     => $ruleId,
            'rule'        => $rule['label'],
            'description' => $rule['description'],
            'subject'     => 'Stock held over 90 days',
            'severity'    => $this->severity($share, '20'),
            'severity_reason' => Format::percent($share, 1) . ' of stock value is in the ' . implode(' and ', $oldLabels)
                . ' band, against ' . $rule['baseline'] . '.',
            'deviation'   => $share,
            'evidence'    => [
                ['label' => 'Value held over 90 days', 'value' => $old, 'formatted' => Format::money($old)],
                ['label' => 'Total stock value', 'value' => $total, 'formatted' => $payload['formatted']],
                ['label' => 'Share', 'value' => $share, 'formatted' => Format::percent($share, 1)],
            ],
            'metric'      => ['id' => $definition->id, 'label' => $definition->label, 'definition' => $definition->definition],
            'period'      => $period->toArray(),
            'drilldown'   => $payload['drilldown'] ?? null,
        ]];
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function concentrationRule(string $ruleId, array $rule, \Aicountly\Api\Metrics\MetricDefinition $definition, Period $period, array $filters, Sources $sources): array
    {
        $answer = $this->query->breakdown($period, $definition->id, 'customer', $filters);
        $sources->merge($answer['sources']);

        if ($answer['result'] === null || !$answer['result']->isAvailable()) {
            return [];
        }

        $payload = $answer['result']->jsonSerialize();
        $rows = $payload['breakdown'];
        if ($rows === []) {
            return [];
        }

        $total = Decimal::sum(array_map(static fn (array $r) => (string) ($r['value'] ?? '0'), $rows));
        if (Decimal::isZero($total)) {
            return [];
        }

        $top = $rows[0];
        $share = Decimal::percentOf((string) ($top['value'] ?? '0'), $total, 1);
        if ($share === null || Decimal::cmp($share, '40') < 0) {
            return [];
        }

        // The breakdown is the source's own top-N, so the denominator is those
        // rows and not the company's whole sales. Say so rather than overstate
        // the concentration.
        $note = $payload['coverage'] === 'partial'
            ? 'The share is of the leading customers the source returned, not of every customer, so the real concentration may be lower.'
            : '';

        return [[
            'fingerprint' => $this->fingerprint($ruleId, (string) $top['key'], $period),
            'rule_id'     => $ruleId,
            'rule'        => $rule['label'],
            'description' => trim($rule['description'] . ' ' . $note),
            'subject'     => (string) $top['label'],
            'severity'    => $this->severity($share, '40'),
            'severity_reason' => (string) $top['label'] . ' accounts for ' . Format::percent($share, 1) . ' of the sales in this breakdown, against ' . $rule['baseline'] . '.',
            'deviation'   => $share,
            'evidence'    => [
                ['label' => (string) $top['label'], 'value' => $top['value'], 'formatted' => (string) $top['formatted']],
                ['label' => 'Total across these customers', 'value' => $total, 'formatted' => Format::money($total)],
                ['label' => 'Share', 'value' => $share, 'formatted' => Format::percent($share, 1)],
            ],
            'metric'      => ['id' => $definition->id, 'label' => $definition->label, 'definition' => $definition->definition],
            'period'      => $period->toArray(),
            'drilldown'   => $top['link'] ?? null,
        ]];
    }

    // -----------------------------------------------------------------------
    // Review
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function review(string $fingerprint, array $input): array
    {
        $status = (string) ($input['status'] ?? '');
        if (!in_array($status, ['open', 'acknowledged', 'dismissed'], true)) {
            Http::validationFailed('A review is acknowledged, dismissed or reopened.', ['field' => 'status']);
        }

        $reason = mb_substr(trim((string) ($input['reason'] ?? '')), 0, 600);
        if ($status === 'dismissed' && $reason === '') {
            // A dismissal without a reason is a finding that quietly disappears.
            Http::validationFailed('Say why this is being dismissed, so the next person reading it knows.', ['field' => 'reason']);
        }

        if (!preg_match('/^[a-f0-9]{40,64}$/', $fingerprint)) {
            Http::validationFailed('That is not an exception reference.', ['field' => 'fingerprint']);
        }

        $ruleId = mb_substr((string) ($input['rule_id'] ?? ''), 0, 64);
        $subject = mb_substr(trim((string) ($input['subject'] ?? '')), 0, 200);

        Db::run(
            'INSERT INTO ' . self::TABLE . ' (public_id, cmp_id, fingerprint, rule_id, subject_label, status, reason, reviewed_by, reviewed_at)
             VALUES (:pid, :cmp, :fp, :rule, :subject, :status, :reason, :by, NOW())
             ON CONFLICT (cmp_id, fingerprint) DO UPDATE
               SET status = EXCLUDED.status,
                   reason = EXCLUDED.reason,
                   reviewed_by = EXCLUDED.reviewed_by,
                   reviewed_at = NOW(),
                   updated_at = NOW()',
            [
                'pid'     => Ids::public('anm'),
                'cmp'     => $this->ctx->cmpId,
                'fp'      => $fingerprint,
                'rule'    => $ruleId,
                'subject' => $subject,
                'status'  => $status,
                'reason'  => $reason,
                'by'      => $this->auth->uuid,
            ],
        );

        $row = Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE cmp_id = :cmp AND fingerprint = :fp',
            ['cmp' => $this->ctx->cmpId, 'fp' => $fingerprint],
        );

        return [
            'id'          => $row['public_id'] ?? null,
            'status'      => $row['status'] ?? $status,
            'reason'      => $row['reason'] ?? $reason,
            'reviewed_by' => $row['reviewed_by'] ?? $this->auth->uuid,
            'reviewed_at' => $row['reviewed_at'] ?? null,
            'notes'       => $this->notes((int) ($row['review_id'] ?? 0)),
        ];
    }

    /** @return array<string, mixed> */
    public function addNote(string $fingerprint, string $note): array
    {
        $note = mb_substr(trim($note), 0, 2000);
        if ($note === '') {
            Http::validationFailed('Write the note first.', ['field' => 'note']);
        }

        $row = Db::first(
            'SELECT review_id FROM ' . self::TABLE . ' WHERE cmp_id = :cmp AND fingerprint = :fp',
            ['cmp' => $this->ctx->cmpId, 'fp' => $fingerprint],
        );

        if ($row === null) {
            Http::notFound('That exception has not been reviewed yet, so there is nothing to add a note to.');
        }

        Db::insert(self::NOTES, [
            'review_id'   => (int) $row['review_id'],
            'cmp_id'      => $this->ctx->cmpId,
            'author_uuid' => $this->auth->uuid,
            'note'        => $note,
        ], 'note_id');

        return ['notes' => $this->notes((int) $row['review_id'])];
    }

    /** @return list<array<string, mixed>> */
    public function notes(int $reviewId): array
    {
        if ($reviewId <= 0) {
            return [];
        }

        return Db::all(
            'SELECT author_uuid, note, created_at FROM ' . self::NOTES . '
              WHERE review_id = :r AND cmp_id = :cmp ORDER BY created_at',
            ['r' => $reviewId, 'cmp' => $this->ctx->cmpId],
        );
    }

    /**
     * @param list<string> $fingerprints
     * @return array<string, array<string, mixed>>
     */
    private function reviews(array $fingerprints): array
    {
        if ($fingerprints === []) {
            return [];
        }

        $rows = Db::all(
            'SELECT * FROM ' . self::TABLE . ' WHERE cmp_id = :cmp AND fingerprint = ANY (:fps)',
            ['cmp' => $this->ctx->cmpId, 'fps' => '{' . implode(',', array_map(static fn (string $f) => '"' . $f . '"', $fingerprints)) . '}'],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['fingerprint']] = $row;
        }

        return $out;
    }

    /**
     * A stable id for "this rule, about this thing, in this period".
     *
     * Hashed so it carries no customer name and no amount, and stable so the
     * same exception is recognised as reviewed the next time the screen is
     * drawn — without either of those being written down.
     */
    private function fingerprint(string $ruleId, string $subject, Period $period): string
    {
        return hash('sha256', implode('|', [
            $this->ctx->cmpId,
            $this->ctx->fyId,
            $ruleId,
            $subject,
            $period->from,
            $period->to,
        ]));
    }

    private function severity(string $magnitude, string $threshold): string
    {
        $double = Decimal::mul($threshold, '2');
        $triple = Decimal::mul($threshold, '3');

        if (Decimal::cmp($magnitude, $triple) >= 0) {
            return 'high';
        }
        if (Decimal::cmp($magnitude, $double) >= 0) {
            return 'medium';
        }

        return 'low';
    }
}
