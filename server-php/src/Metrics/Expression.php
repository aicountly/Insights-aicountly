<?php

declare(strict_types=1);

namespace Aicountly\Api\Metrics;

use Aicountly\Api\Support\Decimal;

/**
 * A custom KPI formula: parsed to a tree, checked, then walked.
 *
 * NOTHING HERE IS EVALUATED AS CODE. There is no eval, no create_function, no
 * assert, no preg_replace /e, and no SQL expression built from the user's text.
 * A formula is tokenised, parsed into a small tree of typed nodes, checked for
 * meaning, and then walked by `evaluate()` with exact decimal arithmetic. The
 * only things a formula may name are metric ids that exist in the catalogue and
 * the handful of functions listed below.
 *
 * WHAT IT REFUSES, and why each one matters:
 *
 *   unknown metric        a typo becomes a KPI that is quietly always
 *                         unavailable rather than a message saying so
 *   cyclic dependency     a KPI defined in terms of itself
 *   too complex           a bound on nodes and depth, because a formula is a
 *                         business definition, not a program
 *   mixed units           rupees plus a percentage is not a number
 *   mixed accounting bases  an operational count added to an accrual figure is
 *                         the double count this product exists to prevent
 *   incompatible grains    a daily flow added to a closing balance
 *   division by zero      answered as NOT APPLICABLE, never as zero
 *
 * The last one is worth stating plainly: a margin on no sales is not 0%, it is
 * a question with no answer, and printing 0% invites somebody to act on it.
 */
final class Expression
{
    /** Nodes and depth a formula may have. Generous for a definition, tiny for a program. */
    public const MAX_NODES = 60;
    public const MAX_DEPTH = 12;
    public const MAX_LENGTH = 500;

    /** @var array<string, int> function name => argument count */
    private const FUNCTIONS = [
        'abs'          => 1,
        'min'          => 2,
        'max'          => 2,
        // Division that answers "not applicable" rather than failing, for the
        // cases where a zero denominator is expected and meaningful.
        'safe_divide'  => 2,
        'percent_of'   => 2,
    ];

    /** @var array<string, mixed> the parsed tree */
    private array $tree;

    /** @var list<string> */
    private array $metricRefs;

    private function __construct(array $tree, array $metricRefs, public readonly string $source)
    {
        $this->tree = $tree;
        $this->metricRefs = $metricRefs;
    }

    /**
     * Parse and check a formula.
     *
     * @param string $ownId the id being defined, so a self-reference is caught
     * @throws ExpressionError
     */
    public static function compile(string $formula, string $ownId = ''): self
    {
        $formula = trim($formula);
        if ($formula === '') {
            throw new ExpressionError('A formula is required.', 'empty');
        }
        if (mb_strlen($formula) > self::MAX_LENGTH) {
            throw new ExpressionError('That formula is longer than ' . self::MAX_LENGTH . ' characters. A KPI definition should read as one line of arithmetic.', 'too_long');
        }

        $tokens = self::tokenise($formula);
        $parser = new ExpressionParser($tokens);
        $tree = $parser->parse();

        $refs = [];
        $nodes = 0;
        self::walk($tree, 0, $nodes, $refs);

        if ($nodes > self::MAX_NODES) {
            throw new ExpressionError('That formula has ' . $nodes . ' parts, and the limit is ' . self::MAX_NODES . '. Break it into two KPIs.', 'too_complex');
        }

        if ($ownId !== '' && in_array($ownId, $refs, true)) {
            throw new ExpressionError('A KPI cannot be defined in terms of itself.', 'cyclic');
        }

        return new self($tree, array_values(array_unique($refs)), $formula);
    }

    /** @return list<string> the metric ids this formula reads */
    public function references(): array
    {
        return $this->metricRefs;
    }

    /** @return array<string, mixed> */
    public function tree(): array
    {
        return $this->tree;
    }

    /**
     * Check the formula against the catalogue.
     *
     * Separate from parsing on purpose: the shape of a formula and its MEANING
     * are different questions, and a user editing one wants the syntax error
     * before the units error.
     *
     * @param callable(string): ?MetricDefinition $resolve
     * @return array{unit:string, precision:int, basis:string, grains:list<string>, is_balance:bool}
     * @throws ExpressionError
     */
    public function check(callable $resolve, int $depth = 0): array
    {
        if ($depth > 6) {
            throw new ExpressionError('These KPIs refer to one another too deeply. Simplify the chain.', 'too_deep');
        }

        return $this->checkNode($this->tree, $resolve, $depth);
    }

    /**
     * @param array<string, mixed>                $node
     * @param callable(string): ?MetricDefinition $resolve
     * @return array{unit:string, precision:int, basis:string, grains:list<string>, is_balance:bool}
     */
    private function checkNode(array $node, callable $resolve, int $depth): array
    {
        switch ($node['type']) {
            case 'number':
                // A literal is unitless and mixes with anything. `* 100` must
                // not be rejected for not being rupees.
                return ['unit' => 'scalar', 'precision' => 4, 'basis' => 'scalar', 'grains' => [], 'is_balance' => false];

            case 'metric':
                $definition = $resolve($node['id']);
                if ($definition === null) {
                    throw new ExpressionError('There is no metric called "' . $node['id'] . '". Pick one from the catalogue.', 'unknown_metric', ['metric_id' => $node['id']]);
                }

                return [
                    'unit'       => $definition->unit,
                    'precision'  => $definition->precision,
                    'basis'      => $definition->accountingBasis,
                    'grains'     => $definition->grains,
                    'is_balance' => $definition->isBalance,
                ];

            case 'unary':
                return $this->checkNode($node['operand'], $resolve, $depth);

            case 'binary':
                return $this->checkBinary($node, $resolve, $depth);

            case 'call':
                return $this->checkCall($node, $resolve, $depth);
        }

        throw new ExpressionError('That formula could not be understood.', 'invalid');
    }

    /**
     * @param array<string, mixed>                $node
     * @param callable(string): ?MetricDefinition $resolve
     * @return array{unit:string, precision:int, basis:string, grains:list<string>, is_balance:bool}
     */
    private function checkBinary(array $node, callable $resolve, int $depth): array
    {
        $left = $this->checkNode($node['left'], $resolve, $depth);
        $right = $this->checkNode($node['right'], $resolve, $depth);
        $op = $node['op'];

        if ($op === '+' || $op === '-') {
            // Addition is where units and bases must agree. Everything below is
            // a real arithmetic error that a spreadsheet would let through.
            if ($left['unit'] !== $right['unit'] && $left['unit'] !== 'scalar' && $right['unit'] !== 'scalar') {
                throw new ExpressionError(
                    'You cannot add a ' . self::unitWord($left['unit']) . ' to a ' . self::unitWord($right['unit']) . '.',
                    'incompatible_units',
                    ['left' => $left['unit'], 'right' => $right['unit']],
                );
            }
            self::assertCompatibleBasis($left, $right);
            self::assertCompatibleGrain($left, $right);

            return self::merge($left, $right);
        }

        if ($op === '*') {
            if ($left['unit'] !== 'scalar' && $right['unit'] !== 'scalar') {
                throw new ExpressionError(
                    'Multiplying two measured quantities gives something with no meaningful unit. Multiply by a plain number instead.',
                    'incompatible_units',
                    ['left' => $left['unit'], 'right' => $right['unit']],
                );
            }

            return self::merge($left, $right);
        }

        // Division. Same-unit over same-unit is a ratio; anything else keeps
        // the numerator's unit only when the denominator is a plain number.
        if ($left['unit'] === $right['unit'] && $left['unit'] !== 'scalar') {
            return ['unit' => 'ratio', 'precision' => 4, 'basis' => 'derived', 'grains' => self::commonGrains($left, $right), 'is_balance' => $left['is_balance'] && $right['is_balance']];
        }
        if ($right['unit'] !== 'scalar') {
            throw new ExpressionError(
                'Dividing a ' . self::unitWord($left['unit']) . ' by a ' . self::unitWord($right['unit']) . ' does not give a figure anyone can read.',
                'incompatible_units',
                ['left' => $left['unit'], 'right' => $right['unit']],
            );
        }

        return $left;
    }

    /**
     * @param array<string, mixed>                $node
     * @param callable(string): ?MetricDefinition $resolve
     * @return array{unit:string, precision:int, basis:string, grains:list<string>, is_balance:bool}
     */
    private function checkCall(array $node, callable $resolve, int $depth): array
    {
        $args = array_map(fn (array $arg) => $this->checkNode($arg, $resolve, $depth), $node['args']);

        return match ($node['name']) {
            'abs'         => $args[0],
            'min', 'max'  => (function () use ($args, $node) {
                if ($args[0]['unit'] !== $args[1]['unit'] && $args[0]['unit'] !== 'scalar' && $args[1]['unit'] !== 'scalar') {
                    throw new ExpressionError(
                        strtoupper($node['name']) . ' compares two figures, so they must be the same kind of thing.',
                        'incompatible_units',
                    );
                }

                return self::merge($args[0], $args[1]);
            })(),
            'safe_divide' => ['unit' => $args[0]['unit'] === $args[1]['unit'] ? 'ratio' : $args[0]['unit'], 'precision' => 4, 'basis' => 'derived', 'grains' => self::commonGrains($args[0], $args[1]), 'is_balance' => false],
            'percent_of'  => ['unit' => 'percent', 'precision' => 2, 'basis' => 'derived', 'grains' => self::commonGrains($args[0], $args[1]), 'is_balance' => false],
            default       => throw new ExpressionError('Unknown function "' . $node['name'] . '".', 'unknown_function'),
        };
    }

    /**
     * Walk the tree with resolved metric values.
     *
     * A metric that is unavailable makes the whole expression unavailable — it
     * is NOT treated as zero. "Missing COGS must not become zero COGS" is the
     * rule, and it is enforced here rather than left to each caller.
     *
     * @param array<string, ?string> $values metric id => exact decimal string, or null when unavailable
     * @return array{status:string, value:?string, reason:?string}
     */
    public function evaluate(array $values): array
    {
        try {
            $value = $this->evaluateNode($this->tree, $values);
        } catch (ExpressionUnavailable $e) {
            return ['status' => MetricResult::UNAVAILABLE, 'value' => null, 'reason' => $e->getMessage()];
        } catch (ExpressionNotApplicable $e) {
            return ['status' => MetricResult::NOT_APPLICABLE, 'value' => null, 'reason' => $e->getMessage()];
        }

        return ['status' => MetricResult::AVAILABLE, 'value' => $value, 'reason' => null];
    }

    /**
     * @param array<string, mixed>   $node
     * @param array<string, ?string> $values
     */
    private function evaluateNode(array $node, array $values): string
    {
        switch ($node['type']) {
            case 'number':
                return $node['value'];

            case 'metric':
                $value = $values[$node['id']] ?? null;
                if ($value === null) {
                    throw new ExpressionUnavailable($node['id'] . ' could not be read, so this KPI has no value. It is not zero.');
                }

                return $value;

            case 'unary':
                return Decimal::negate($this->evaluateNode($node['operand'], $values));

            case 'binary':
                $left = $this->evaluateNode($node['left'], $values);
                $right = $this->evaluateNode($node['right'], $values);

                return match ($node['op']) {
                    '+' => Decimal::add($left, $right),
                    '-' => Decimal::sub($left, $right),
                    '*' => Decimal::mul($left, $right),
                    '/' => Decimal::div($left, $right, 6)
                        ?? throw new ExpressionNotApplicable('The divisor is zero, so this KPI is not applicable for this period rather than zero.'),
                    default => throw new ExpressionUnavailable('Unsupported operator.'),
                };

            case 'call':
                return $this->evaluateCall($node, $values);
        }

        throw new ExpressionUnavailable('That formula could not be evaluated.');
    }

    /**
     * @param array<string, mixed>   $node
     * @param array<string, ?string> $values
     */
    private function evaluateCall(array $node, array $values): string
    {
        $args = array_map(fn (array $arg) => $this->evaluateNode($arg, $values), $node['args']);

        return match ($node['name']) {
            'abs' => Decimal::isNegative($args[0]) ? Decimal::negate($args[0]) : $args[0],
            'min' => Decimal::cmp($args[0], $args[1]) <= 0 ? $args[0] : $args[1],
            'max' => Decimal::max($args[0], $args[1]),
            'safe_divide' => Decimal::div($args[0], $args[1], 6)
                ?? throw new ExpressionNotApplicable('The divisor is zero, so this KPI is not applicable for this period.'),
            'percent_of' => Decimal::percentOf($args[0], $args[1], 4)
                ?? throw new ExpressionNotApplicable('The base is zero, so a percentage of it is not applicable.'),
            default => throw new ExpressionUnavailable('Unknown function.'),
        };
    }

    // -----------------------------------------------------------------------
    // Tokenising
    // -----------------------------------------------------------------------

    /**
     * @return list<array{type:string, value:string}>
     * @throws ExpressionError
     */
    private static function tokenise(string $formula): array
    {
        $tokens = [];
        $length = strlen($formula);
        $i = 0;

        while ($i < $length) {
            $char = $formula[$i];

            if (ctype_space($char)) {
                $i++;
                continue;
            }

            if (str_contains('+-*/(),', $char)) {
                $tokens[] = ['type' => $char === '(' || $char === ')' || $char === ',' ? $char : 'op', 'value' => $char];
                $i++;
                continue;
            }

            if (ctype_digit($char) || ($char === '.' && $i + 1 < $length && ctype_digit($formula[$i + 1]))) {
                $start = $i;
                $seenDot = false;
                while ($i < $length && (ctype_digit($formula[$i]) || ($formula[$i] === '.' && !$seenDot))) {
                    if ($formula[$i] === '.') {
                        $seenDot = true;
                    }
                    $i++;
                }
                $tokens[] = ['type' => 'number', 'value' => rtrim(substr($formula, $start, $i - $start), '.')];
                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                $start = $i;
                while ($i < $length && (ctype_alnum($formula[$i]) || $formula[$i] === '_' || $formula[$i] === '.')) {
                    $i++;
                }
                $word = substr($formula, $start, $i - $start);
                $tokens[] = ['type' => str_contains($word, '.') ? 'metric' : 'name', 'value' => $word];
                continue;
            }

            // Anything else is not arithmetic. Naming the character is what
            // turns "invalid formula" into something a person can fix.
            throw new ExpressionError(
                'The character "' . $char . '" is not allowed in a formula. Use metric ids, numbers, + - * / ( ) and the functions '
                . implode(', ', array_keys(self::FUNCTIONS)) . '.',
                'illegal_character',
                ['character' => $char, 'position' => $i],
            );
        }

        if ($tokens === []) {
            throw new ExpressionError('A formula is required.', 'empty');
        }

        return $tokens;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string>         $refs
     */
    private static function walk(array $node, int $depth, int &$nodes, array &$refs): void
    {
        $nodes++;
        if ($depth > self::MAX_DEPTH) {
            throw new ExpressionError('That formula nests too deeply. Break it into two KPIs.', 'too_deep');
        }

        switch ($node['type']) {
            case 'metric':
                $refs[] = $node['id'];
                break;
            case 'unary':
                self::walk($node['operand'], $depth + 1, $nodes, $refs);
                break;
            case 'binary':
                self::walk($node['left'], $depth + 1, $nodes, $refs);
                self::walk($node['right'], $depth + 1, $nodes, $refs);
                break;
            case 'call':
                foreach ($node['args'] as $arg) {
                    self::walk($arg, $depth + 1, $nodes, $refs);
                }
                break;
        }
    }

    /** @return array<string, int> */
    public static function functions(): array
    {
        return self::FUNCTIONS;
    }

    private static function unitWord(string $unit): string
    {
        return match ($unit) {
            'currency'          => 'money amount',
            'percent'           => 'percentage',
            'percentage_points' => 'percentage-point figure',
            'count'             => 'count',
            'days'              => 'number of days',
            'quantity'          => 'quantity',
            'ratio'             => 'ratio',
            'scalar'            => 'plain number',
            default             => $unit,
        };
    }

    /**
     * @param array{basis:string} $left
     * @param array{basis:string} $right
     */
    private static function assertCompatibleBasis(array $left, array $right): void
    {
        $accounting = ['accrual', 'cash', 'balance'];
        $leftIsAccounting = in_array($left['basis'], $accounting, true);
        $rightIsAccounting = in_array($right['basis'], $accounting, true);

        if ($left['basis'] === 'scalar' || $right['basis'] === 'scalar' || $left['basis'] === 'derived' || $right['basis'] === 'derived') {
            return;
        }

        if ($leftIsAccounting !== $rightIsAccounting) {
            throw new ExpressionError(
                'One of these is an accounting figure and the other is an operational one. Adding them counts the same business twice.',
                'incompatible_basis',
                ['left' => $left['basis'], 'right' => $right['basis']],
            );
        }

        // A flow over a period and a balance at a date are both accounting
        // figures and still must not be added: one is a rate, the other a level.
        if ($left['basis'] === 'balance' xor $right['basis'] === 'balance') {
            throw new ExpressionError(
                'One of these is a balance at a date and the other is a total over a period. They cannot be added.',
                'incompatible_basis',
                ['left' => $left['basis'], 'right' => $right['basis']],
            );
        }
    }

    /**
     * @param array{grains:list<string>} $left
     * @param array{grains:list<string>} $right
     */
    private static function assertCompatibleGrain(array $left, array $right): void
    {
        if ($left['grains'] === [] || $right['grains'] === []) {
            return;
        }
        if (array_intersect($left['grains'], $right['grains']) === []) {
            throw new ExpressionError(
                'These two metrics are not reported at any common time grain, so they cannot be combined.',
                'incompatible_grain',
                ['left' => $left['grains'], 'right' => $right['grains']],
            );
        }
    }

    /**
     * @param array{grains:list<string>} $left
     * @param array{grains:list<string>} $right
     * @return list<string>
     */
    private static function commonGrains(array $left, array $right): array
    {
        if ($left['grains'] === []) {
            return $right['grains'];
        }
        if ($right['grains'] === []) {
            return $left['grains'];
        }

        return array_values(array_intersect($left['grains'], $right['grains']));
    }

    /**
     * @param array{unit:string, precision:int, basis:string, grains:list<string>, is_balance:bool} $left
     * @param array{unit:string, precision:int, basis:string, grains:list<string>, is_balance:bool} $right
     * @return array{unit:string, precision:int, basis:string, grains:list<string>, is_balance:bool}
     */
    private static function merge(array $left, array $right): array
    {
        $unit = $left['unit'] === 'scalar' ? $right['unit'] : $left['unit'];
        $basis = $left['basis'] === 'scalar' ? $right['basis'] : $left['basis'];

        return [
            'unit'       => $unit,
            'precision'  => max($left['precision'], $right['precision']),
            'basis'      => $basis,
            'grains'     => self::commonGrains($left, $right),
            'is_balance' => $left['is_balance'] || $right['is_balance'],
        ];
    }
}
