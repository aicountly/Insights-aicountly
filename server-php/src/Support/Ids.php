<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

/**
 * Public identifiers for rows this product owns.
 *
 * Dashboards are shared by link inside a tenant, so their id travels in URLs
 * and in other people's browser history. A BIGSERIAL there is an invitation to
 * try the next number — and while every read is also authorised, an id that
 * enumerates turns one mistake into a whole tenant's worth of exposure.
 *
 * So the database keeps its integer primary key for joins, and every row that
 * appears in a URL also carries an unguessable `public_id`. The API speaks only
 * in public ids.
 */
final class Ids
{
    /** 26 characters of crockford-ish base32 over 128 bits. */
    public static function public(string $prefix = ''): string
    {
        $alphabet = '0123456789abcdefghjkmnpqrstvwxyz';
        $bytes = random_bytes(16);
        $out = '';
        foreach (str_split($bytes) as $byte) {
            $value = ord($byte);
            $out .= $alphabet[$value >> 3];
            $out .= $alphabet[(($value & 0x07) << 2) | random_int(0, 3)];
        }

        return ($prefix === '' ? '' : $prefix . '_') . substr($out, 0, 26);
    }

    /** Reject anything that is not one of ours before it reaches a query. */
    public static function isValid(string $candidate, string $prefix = ''): bool
    {
        $pattern = $prefix === ''
            ? '/^[0-9a-z]{20,32}$/'
            : '/^' . preg_quote($prefix, '/') . '_[0-9a-z]{20,32}$/';

        return preg_match($pattern, $candidate) === 1;
    }

    /** A deterministic, non-reversible key for a request-scoped memo. */
    public static function memoKey(string ...$parts): string
    {
        return substr(hash('sha256', implode('|', $parts)), 0, 40);
    }
}
