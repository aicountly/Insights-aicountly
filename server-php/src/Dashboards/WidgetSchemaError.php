<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

/**
 * A widget configuration that cannot be accepted, and which field is wrong.
 *
 * The field matters: a builder that says "invalid configuration" makes somebody
 * click through nine properties to find the one that is wrong.
 */
final class WidgetSchemaError extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, public readonly string $field, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
