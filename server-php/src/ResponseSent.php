<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * The response a controller produced, thrown instead of exited under CLI.
 *
 * Http::json() exits under a web SAPI. That is right in production and useless
 * in a test, which needs to assert on what the controller actually answered.
 * Under CLI the same call throws this instead, so the test suite exercises the
 * real controller — permission checks, context checks and all — rather than
 * reaching past it into the services where those checks are not.
 */
final class ResponseSent extends \RuntimeException
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $status,
        public readonly array $payload,
    ) {
        parent::__construct('HTTP ' . $status);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $data = $this->payload['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    public function errorCode(): string
    {
        $error = $this->payload['error'] ?? null;

        return is_array($error) ? (string) ($error['code'] ?? '') : '';
    }
}
