<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Whether this session owns the company it is working in, per Aicountly Manage.
 *
 * The obvious place to read a role is the session. It is not there:
 * my.aicountly.com's `validatesession` answers `status`, `uuid_aictly`,
 * `aic_auth_id` and `aic_ses_id` and nothing else — it is pure authentication
 * and has never carried a company id, let alone a role in one. Any product
 * reading `acs_type` off that payload reads null forever.
 *
 * Ownership is per company and Manage owns it. `GET /api/companyinfo` reports
 * it three ways for three generations of caller — `access_type` (1 = owner),
 * `ownership` ("owner" / "shared") and `is_creator` — so all three are read
 * here, most specific first.
 *
 * UNKNOWN IS NOT ZERO. A shape this does not recognise returns null, never 0,
 * so a caller can tell "Manage says you are not the owner" from "Manage never
 * said" — the second is a fault here and should be reported as one.
 *
 * Ported unchanged in behaviour from Purchases, deliberately: a fleet with one
 * reading of Manage's payload is a fleet where a company owner is an owner
 * everywhere.
 */
final class CompanyAccess
{
    public const OWNER = 1;

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): ?int
    {
        $direct = self::fromRow($payload);
        if ($direct !== null) {
            return $direct;
        }

        foreach (['data', 'company'] as $key) {
            $nested = $payload[$key] ?? null;
            if (is_array($nested) && !isset($nested[0])) {
                $found = self::fromRow($nested);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): ?int
    {
        foreach (['access_type', 'acs_type'] as $key) {
            if (array_key_exists($key, $row)) {
                $numeric = self::numeric($row[$key]);
                if ($numeric !== null) {
                    return $numeric;
                }
            }
        }

        foreach (['ownership', 'access', 'access_name', 'access_label'] as $key) {
            if (array_key_exists($key, $row)) {
                $label = self::label($row[$key]);
                if ($label !== null) {
                    return $label;
                }
            }
        }

        // Last, and only as a positive: `is_creator: false` means "you did not
        // create this company", which is not the same as "you are not its
        // owner" in every payload that carries it.
        if (self::truthy($row['is_creator'] ?? null)) {
            return self::OWNER;
        }

        return null;
    }

    private static function numeric(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? self::OWNER : 0;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private static function label(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        $label = strtolower(trim((string) $value));
        if ($label === 'owner' || $label === 'creator') {
            return self::OWNER;
        }

        return in_array($label, ['shared', 'delegated', 'member', 'user', 'viewer', 'editor'], true) ? 0 : null;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 't'], true);
        }

        return false;
    }
}
