<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

/**
 * Server-side formatting of exact decimal strings into display text.
 *
 * THE SERVER FORMATS THE NUMBER, AND THE BROWSER PRINTS IT. A JavaScript
 * `Number` is a float: a payables total that survived exact arithmetic through
 * PostgreSQL and PHP loses paise the moment it is parsed for display. So every
 * figure travels as BOTH an exact string (`value`) and the text to show
 * (`formatted`), and the front end never re-derives the second from the first.
 *
 * Indian digit grouping is the default because these are Indian books: 12,34,567
 * is not 1,234,567 with the commas in odd places, it is how the number is read.
 */
final class Format
{
    /** Currency symbols this fleet actually handles. Anything else prints its ISO code. */
    private const SYMBOLS = [
        'INR' => "\u{20B9}",
        'USD' => '$',
        'EUR' => "\u{20AC}",
        'GBP' => "\u{00A3}",
        'AED' => 'AED ',
        'SGD' => 'S$',
    ];

    /**
     * Money, grouped for the given locale style.
     *
     * @param string $style 'indian' (12,34,567.89) or 'western' (1,234,567.89)
     */
    public static function money(?string $value, string $currency = 'INR', int $precision = 2, string $style = 'indian'): string
    {
        if ($value === null) {
            return '—';
        }

        $symbol = self::SYMBOLS[strtoupper($currency)] ?? (strtoupper($currency) . ' ');
        $rounded = Decimal::round($value, $precision);
        $negative = Decimal::isNegative($rounded);
        $digits = ltrim($rounded, '-');

        return ($negative ? '-' : '') . $symbol . self::group($digits, $precision, $style);
    }

    /** A plain number — quantities, counts, days. */
    public static function number(?string $value, int $precision = 0, string $style = 'indian'): string
    {
        if ($value === null) {
            return '—';
        }
        $rounded = Decimal::round($value, $precision);
        $negative = Decimal::isNegative($rounded);

        return ($negative ? '-' : '') . self::group(ltrim($rounded, '-'), $precision, $style);
    }

    /** A percentage. `points` marks it as percentage POINTS, which is a different quantity. */
    public static function percent(?string $value, int $precision = 1, bool $points = false): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return self::number($value, $precision, 'western') . ($points ? ' pp' : '%');
    }

    /** A signed change, so a reader can see direction without reading the digits. */
    public static function signed(?string $value, int $precision = 1, bool $points = false): string
    {
        if ($value === null) {
            return 'n/a';
        }
        $prefix = Decimal::isNegative($value) || Decimal::isZero($value) ? '' : '+';

        return $prefix . self::percent($value, $precision, $points);
    }

    public static function date(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', substr($iso, 0, 10));

        return $parsed === false ? $iso : $parsed->format('j M Y');
    }

    /** A value formatted according to a metric's declared unit. */
    public static function forUnit(?string $value, string $unit, string $currency, int $precision, string $style = 'indian'): string
    {
        return match ($unit) {
            'currency'          => self::money($value, $currency, $precision, $style),
            'percent'           => self::percent($value, $precision),
            'percentage_points' => self::percent($value, $precision, true),
            'days'              => $value === null ? '—' : self::number($value, $precision, 'western') . ' days',
            'count'             => self::number($value, $precision, $style),
            'ratio'             => $value === null ? '—' : self::number($value, max(2, $precision), 'western') . '×',
            default             => self::number($value, $precision, $style),
        };
    }

    /**
     * Group the integer part and re-attach the fraction.
     *
     * Indian grouping is three digits, then twos: 1,00,00,000. Doing that with
     * number_format and a locale is how a build without the intl data silently
     * falls back to western grouping.
     */
    private static function group(string $digits, int $precision, string $style): string
    {
        $parts = explode('.', $digits, 2);
        $whole = $parts[0] === '' ? '0' : $parts[0];
        $fraction = $parts[1] ?? '';

        if ($precision > 0) {
            $fraction = str_pad(substr($fraction, 0, $precision), $precision, '0');
        } else {
            $fraction = '';
        }

        $grouped = $style === 'western' ? self::groupWestern($whole) : self::groupIndian($whole);

        return $fraction === '' ? $grouped : $grouped . '.' . $fraction;
    }

    private static function groupWestern(string $whole): string
    {
        $out = '';
        $count = 0;
        for ($i = strlen($whole) - 1; $i >= 0; $i--) {
            $out = $whole[$i] . $out;
            if (++$count % 3 === 0 && $i > 0) {
                $out = ',' . $out;
            }
        }

        return $out;
    }

    private static function groupIndian(string $whole): string
    {
        if (strlen($whole) <= 3) {
            return $whole;
        }

        $last3 = substr($whole, -3);
        $rest = substr($whole, 0, -3);

        $out = '';
        $count = 0;
        for ($i = strlen($rest) - 1; $i >= 0; $i--) {
            $out = $rest[$i] . $out;
            if (++$count % 2 === 0 && $i > 0) {
                $out = ',' . $out;
            }
        }

        return $out . ',' . $last3;
    }
}
