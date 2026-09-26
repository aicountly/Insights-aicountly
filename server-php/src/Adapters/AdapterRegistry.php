<?php

declare(strict_types=1);

namespace Aicountly\Api\Adapters;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\OperationalClient;
use Aicountly\Api\Context;

/**
 * Every source Insights knows how to read, built for one caller.
 *
 * BUILT PER CALLER, not shared: each adapter holds the viewer's own session key
 * because every read it makes is made AS THAT PERSON. A registry cached across
 * requests would be a registry holding somebody else's session, and the first
 * time two people used the product at once it would show one of them the
 * other's data. There is no static instance here for that reason.
 */
final class AdapterRegistry
{
    /** @var array<string, SourceAdapter> */
    private array $adapters = [];

    public function __construct(Auth $auth)
    {
        $this->adapters['books'] = new BooksAdapter($auth);
        $this->adapters['inventory'] = new InventoryAdapter($auth);

        foreach (OperationalClient::products() as $product) {
            $this->adapters[$product] = new OperationalAdapter($product, $auth);
        }
    }

    /** @return array<string, SourceAdapter> */
    public function all(): array
    {
        return $this->adapters;
    }

    public function get(string $product): ?SourceAdapter
    {
        return $this->adapters[$product] ?? null;
    }

    /**
     * The adapters that between them can answer these metrics.
     *
     * @param list<string> $metricIds
     * @return array<string, list<string>> product => metric ids it owns from the request
     */
    public function route(array $metricIds): array
    {
        $plan = [];
        foreach ($this->adapters as $product => $adapter) {
            $mine = array_values(array_intersect($metricIds, $adapter->metrics()));
            if ($mine !== []) {
                $plan[$product] = $mine;
            }
        }

        return $plan;
    }

    /**
     * Status for every source, as this viewer sees it.
     *
     * @return list<array<string, mixed>>
     */
    public function statuses(Context $ctx): array
    {
        $out = [];
        foreach ($this->adapters as $adapter) {
            $out[] = $adapter->status($ctx);
        }

        return $out;
    }
}
