<?php

declare(strict_types=1);

namespace Aicountly\Api\Metrics;

/** A formula that cannot be accepted. Carries a code the UI can act on. */
final class ExpressionError extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, public readonly string $errorCode, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
