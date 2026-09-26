<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

/**
 * Which products answered, and when.
 *
 * A dashboard composed from five live products will sometimes be composed from
 * three. This is how the screen says so, per source, instead of quietly
 * rendering a smaller number. "We could not ask" and "we asked and the answer
 * was zero" are different facts and are never merged here.
 *
 * `as_of` is when WE fetched, and is reported as exactly that. Where a product
 * tells us how fresh its own answer is, that goes in `source_as_of` and is the
 * one a reader should trust. Nothing here is ever labelled "real time" on the
 * strength of a fetch timestamp.
 */
final class Sources
{
    public const READY       = 'ready';
    public const DEGRADED    = 'degraded';
    public const UNAVAILABLE = 'unavailable';
    public const NOT_CONFIGURED = 'not_configured';

    /** @var array<string, array<string, mixed>> */
    private array $sources = [];

    public function ready(string $id, string $label, ?string $sourceAsOf = null): self
    {
        $this->sources[$id] = [
            'id'            => $id,
            'label'         => $label,
            'status'        => self::READY,
            'status_label'  => 'Connected',
            'fetched_at'    => gmdate('c'),
            'source_as_of'  => $sourceAsOf,
            'message'       => null,
        ];

        return $this;
    }

    /** Answered, but not with everything that was asked for. */
    public function degraded(string $id, string $label, string $message): self
    {
        $this->sources[$id] = [
            'id'            => $id,
            'label'         => $label,
            'status'        => self::DEGRADED,
            'status_label'  => 'Partial',
            'fetched_at'    => gmdate('c'),
            'source_as_of'  => null,
            'message'       => $message,
        ];

        return $this;
    }

    public function unavailable(string $id, string $label, string $message, ?int $status = null): self
    {
        $this->sources[$id] = [
            'id'            => $id,
            'label'         => $label,
            'status'        => self::UNAVAILABLE,
            'status_label'  => $status === 403 ? 'Not permitted' : 'Unavailable',
            'fetched_at'    => gmdate('c'),
            'source_as_of'  => null,
            'message'       => $message,
            'http_status'   => $status,
        ];

        return $this;
    }

    /** No base URL, no service key — an administrator has not connected it yet. */
    public function notConfigured(string $id, string $label, string $message): self
    {
        $this->sources[$id] = [
            'id'           => $id,
            'label'        => $label,
            'status'       => self::NOT_CONFIGURED,
            'status_label' => 'Setup required',
            'fetched_at'   => null,
            'source_as_of' => null,
            'message'      => $message,
        ];

        return $this;
    }

    /** Not asked at all — a panel not on this screen, or a metric nobody requested. */
    public function notRequested(string $id, string $label, string $message): self
    {
        $this->sources[$id] = [
            'id'           => $id,
            'label'        => $label,
            'status'       => self::UNAVAILABLE,
            'status_label' => 'Not requested',
            'fetched_at'   => null,
            'source_as_of' => null,
            'message'      => $message,
        ];

        return $this;
    }

    public function isReady(string $id): bool
    {
        return ($this->sources[$id]['status'] ?? null) === self::READY;
    }

    public function has(string $id): bool
    {
        return isset($this->sources[$id]);
    }

    /**
     * Record the outcome of one adapter call in a line.
     *
     * @param array{ok:bool, status?:int, error?:?string} $result
     */
    public function record(string $id, string $label, array $result, string $unavailableMessage): bool
    {
        if ($result['ok'] ?? false) {
            $this->ready($id, $label);

            return true;
        }

        $status = (int) ($result['status'] ?? 0);
        $detail = match (true) {
            $status === 403 => ' You do not have access to that data in ' . $label . '.',
            $status === 401 => ' ' . $label . ' did not accept this session.',
            $status === 404 => ' ' . $label . ' does not offer that report.',
            $status === 0   => ' ' . $label . ' could not be reached.',
            default         => '',
        };

        $this->unavailable($id, $label, $unavailableMessage . $detail, $status ?: null);

        return false;
    }

    /** Merge another collector's findings, most recent wins. */
    public function merge(self $other): self
    {
        foreach ($other->sources as $id => $entry) {
            $this->sources[$id] = $entry;
        }

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        $rows = array_values($this->sources);
        usort($rows, static fn (array $a, array $b) => strcmp((string) $a['label'], (string) $b['label']));

        return $rows;
    }
}
