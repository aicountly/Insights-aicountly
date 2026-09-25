<?php

declare(strict_types=1);

namespace Aicountly\Api\Metrics;

/**
 * Recursive-descent parser. Precedence: unary minus, then * /, then + -.
 *
 * Separate from Expression so the parse state is not mixed with the checked,
 * immutable result.
 */
final class ExpressionParser
{
    private int $position = 0;

    /** @param list<array{type:string, value:string}> $tokens */
    public function __construct(private readonly array $tokens)
    {
    }

    /** @return array<string, mixed> */
    public function parse(): array
    {
        $tree = $this->expression();
        if ($this->position < count($this->tokens)) {
            throw new ExpressionError('There is something extra after the end of the formula: "' . $this->tokens[$this->position]['value'] . '".', 'trailing_input');
        }

        return $tree;
    }

    /** @return array<string, mixed> */
    private function expression(): array
    {
        $node = $this->term();

        while ($this->peekOp('+') || $this->peekOp('-')) {
            $op = $this->tokens[$this->position]['value'];
            $this->position++;
            $node = ['type' => 'binary', 'op' => $op, 'left' => $node, 'right' => $this->term()];
        }

        return $node;
    }

    /** @return array<string, mixed> */
    private function term(): array
    {
        $node = $this->factor();

        while ($this->peekOp('*') || $this->peekOp('/')) {
            $op = $this->tokens[$this->position]['value'];
            $this->position++;
            $node = ['type' => 'binary', 'op' => $op, 'left' => $node, 'right' => $this->factor()];
        }

        return $node;
    }

    /** @return array<string, mixed> */
    private function factor(): array
    {
        if ($this->peekOp('-')) {
            $this->position++;

            return ['type' => 'unary', 'op' => '-', 'operand' => $this->factor()];
        }
        if ($this->peekOp('+')) {
            $this->position++;

            return $this->factor();
        }

        return $this->primary();
    }

    /** @return array<string, mixed> */
    private function primary(): array
    {
        $token = $this->tokens[$this->position] ?? null;
        if ($token === null) {
            throw new ExpressionError('The formula ends before it is finished.', 'unexpected_end');
        }

        if ($token['type'] === 'number') {
            $this->position++;

            return ['type' => 'number', 'value' => $token['value'] === '' ? '0' : $token['value']];
        }

        if ($token['type'] === 'metric') {
            $this->position++;
            if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $token['value']) !== 1) {
                throw new ExpressionError('"' . $token['value'] . '" is not a metric id. They look like finance.net_revenue.', 'invalid_metric_id');
            }

            return ['type' => 'metric', 'id' => $token['value']];
        }

        if ($token['type'] === 'name') {
            $name = strtolower($token['value']);
            $arity = Expression::functions()[$name] ?? null;
            if ($arity === null) {
                throw new ExpressionError(
                    '"' . $token['value'] . '" is not a metric id or a function. Metric ids look like finance.net_revenue; the functions are '
                    . implode(', ', array_keys(Expression::functions())) . '.',
                    'unknown_name',
                );
            }
            $this->position++;
            $this->expect('(');

            $args = [$this->expression()];
            while ($this->peek(',')) {
                $this->position++;
                $args[] = $this->expression();
            }
            $this->expect(')');

            if (count($args) !== $arity) {
                throw new ExpressionError($name . ' takes ' . $arity . ' argument' . ($arity === 1 ? '' : 's') . ', and ' . count($args) . ' were given.', 'wrong_arity');
            }

            return ['type' => 'call', 'name' => $name, 'args' => $args];
        }

        if ($token['type'] === '(') {
            $this->position++;
            $node = $this->expression();
            $this->expect(')');

            return $node;
        }

        throw new ExpressionError('"' . $token['value'] . '" cannot appear here.', 'unexpected_token');
    }

    private function peek(string $type): bool
    {
        return ($this->tokens[$this->position]['type'] ?? '') === $type;
    }

    private function peekOp(string $op): bool
    {
        $token = $this->tokens[$this->position] ?? null;

        return $token !== null && $token['type'] === 'op' && $token['value'] === $op;
    }

    private function expect(string $type): void
    {
        if (!$this->peek($type)) {
            throw new ExpressionError('Expected "' . $type . '" in the formula.', 'expected_' . $type);
        }
        $this->position++;
    }
}
