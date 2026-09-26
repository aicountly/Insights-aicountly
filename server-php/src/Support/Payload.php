<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

/**
 * Reading a value out of another product's JSON, safely.
 *
 * Every figure that crosses a product boundary arrives as a JSON number, which
 * json_decode turns into a PHP float. A float is the last place a payable
 * should live, so nothing here returns one: `decimal()` converts on the way in
 * and everything downstream is an exact string.
 *
 * `path()` walks a dotted path and returns null — never 0, never '' — when any
 * segment is missing. That distinction is the whole reason this file exists: a
 * missing field and a field worth zero are different facts, and a helper that
 * conflated them would put "₹0.00 receivable" on screen for a company whose
 * dashboard endpoint had simply changed shape.
 */
final class Payload
{
    /**
     * @param array<string|int, mixed> $payload
     */
    public static function path(array $payload, string $path): mixed
    {
        $cursor = $payload;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * An exact decimal string at that path, or null if it is not a number.
     *
     * @param array<string|int, mixed> $payload
     */
    public static function decimal(array $payload, string $path): ?string
    {
        return Decimal::parse(self::path($payload, $path));
    }

    /**
     * The first path that yields a number.
     *
     * Products spell the same field differently across generations of their own
     * API — `amount`, `amt`, `value`. Trying them in order beats each caller
     * having its own opinion about which one is current.
     *
     * @param array<string|int, mixed> $payload
     * @param list<string>             $paths
     */
    public static function firstDecimal(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = self::decimal($payload, $path);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string|int, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public static function rows(array $payload, string $path): array
    {
        $value = self::path($payload, $path);
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** @param array<string|int, mixed> $payload */
    public static function text(array $payload, string $path, string $default = ''): string
    {
        $value = self::path($payload, $path);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** @param array<string|int, mixed> $payload */
    public static function integer(array $payload, string $path, ?int $default = null): ?int
    {
        $value = self::path($payload, $path);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * The `data` envelope of a fleet response, whatever shape it arrived in.
     *
     * @param array{ok?:bool, body?:?array} $result
     * @return array<string|int, mixed>
     */
    public static function data(array $result): array
    {
        $body = $result['body'] ?? null;
        if (!is_array($body)) {
            return [];
        }
        $data = $body['data'] ?? $body;

        return is_array($data) ? $data : [];
    }

    /**
     * The `meta` envelope, where list endpoints put totals and summaries.
     *
     * @param array{ok?:bool, body?:?array} $result
     * @return array<string|int, mixed>
     */
    public static function meta(array $result): array
    {
        $body = $result['body'] ?? null;
        $meta = is_array($body) ? ($body['meta'] ?? []) : [];

        return is_array($meta) ? $meta : [];
    }

    /**
     * A label a source supplied, clipped and stripped of control characters.
     *
     * Supplier and customer names are USER INPUT in another product. They reach
     * chart legends, CSV cells and AI prompts, so they are treated as data
     * everywhere: never interpolated into a query, never obeyed as an
     * instruction, and never long enough to push a layout off the page.
     */
    public static function label(mixed $value, string $fallback = '—', int $maxLength = 120): string
    {
        if (!is_scalar($value)) {
            return $fallback;
        }
        $text = trim((string) $value);
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return $fallback;
        }

        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength - 1) . "\u{2026}" : $text;
    }
}
