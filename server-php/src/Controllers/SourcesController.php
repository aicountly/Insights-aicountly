<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Adapters\AdapterRegistry;
use Aicountly\Api\Ai\ConsoleCredentials;
use Aicountly\Api\Http;
use Aicountly\Api\Metrics\MetricCatalog;
use Aicountly\Api\Permissions;

/**
 * Which products are connected, and what this viewer may see in each.
 *
 * THE POINT OF THIS SCREEN IS HONESTY. A compiled adapter is not a working
 * integration, and nothing on this page is inferred from the code being
 * present: every row is a live call, made with the viewer's own session, on the
 * request that drew it. A product that is configured and down says so; one that
 * is up and refusing this person says that instead, which is a different
 * problem with a different fix.
 *
 * It also names the metrics each source can answer and the ones that have no
 * binding at all, so "why is gross margin always unavailable" has an answer on
 * a screen rather than in a repository.
 */
final class SourcesController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'source.view');

        $registry = new AdapterRegistry($auth);
        $statuses = $registry->statuses($ctx);

        $bound = [];
        foreach ($statuses as $status) {
            foreach ($status['metrics'] as $metricId) {
                $bound[$metricId] = true;
            }
        }

        // Metrics declared in the catalogue that nothing can answer. Declaring
        // them is what makes the gap visible; listing them here is what makes
        // it explicable.
        $unbound = [];
        foreach (MetricCatalog::all() as $id => $definition) {
            if (isset($bound[$id]) || $definition->dependsOn !== []) {
                continue;
            }
            $unbound[] = [
                'metric_id'  => $id,
                'label'      => $definition->label,
                'owner'      => $definition->owningProduct,
                'reason'     => $definition->binding,
                'definition' => $definition->definition,
            ];
        }

        Http::data([
            'sources'          => $statuses,
            'unbound_metrics'  => $unbound,
            'ai'               => self::aiRow($ctx, $auth),
            'checked_at'       => gmdate('c'),
            'note'             => 'Each row is a live call made with your own session. A product you cannot open in its own app will refuse Insights too, and that is correct.',
        ]);
    }

    /**
     * One source, re-checked on demand, with its capabilities spelled out.
     */
    public static function show(string $product): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'source.view');

        $registry = new AdapterRegistry($auth);
        $adapter = $registry->get(strtolower($product));

        if ($adapter === null) {
            Http::notFound('Insights has no adapter for "' . htmlspecialchars($product, ENT_QUOTES) . '".');
        }

        $status = $adapter->status($ctx);

        $metrics = [];
        foreach ($adapter->metrics() as $metricId) {
            $definition = MetricCatalog::get($metricId);
            if ($definition === null) {
                continue;
            }
            $metrics[] = [
                'id'          => $metricId,
                'label'       => $definition->label,
                'definition'  => $definition->definition,
                'binding'     => $definition->binding,
                'unit'        => $definition->unit,
                'dimensions'  => $definition->dimensions,
                'grains'      => $definition->grains,
                'permissions' => $definition->sourcePermissions,
            ];
        }

        Http::data($status + ['metric_details' => $metrics]);
    }

    /** @return array<string, mixed> */
    private static function aiRow(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth): array
    {
        $status = ConsoleCredentials::status();
        if (!Permissions::allows($ctx, $auth, 'settings.manage')) {
            $status['admin_hint'] = null;
        }

        return $status;
    }
}
