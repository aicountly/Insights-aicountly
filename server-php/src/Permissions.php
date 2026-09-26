<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * This product's own permissions, layered over the portal identity.
 *
 * WHAT THESE DO AND DO NOT CONTROL. They control what a person may do *in
 * Insights*: build a dashboard, publish it, share it, define a KPI, review an
 * anomaly, export a report. They do NOT grant access to business data. Every
 * figure on every widget is fetched from the owning product with the viewer's
 * own portal session, so Books decides who may see a receivable and Inventory
 * decides who may see a valuation — exactly as they would if the person opened
 * those products directly.
 *
 * That separation is the reason a shared dashboard is safe to share: it hands
 * over a *configuration*, and the recipient's own permissions decide what it
 * renders. `dashboard.view` on somebody else's dashboard and no `reports.view`
 * in Books gives you a layout full of "you do not have access to that in Smart
 * Books", which is the correct outcome.
 *
 * ENFORCED IN THE BACKEND. Hiding a menu item in React is a courtesy, not a
 * control — the API route is one curl away.
 */
final class Permissions
{
    public const TABLE_PROFILES    = 'insights_permission_profiles';
    public const TABLE_ASSIGNMENTS = 'insights_permission_assignments';

    /** @var array<string, array<string, string>> */
    public const CATALOG = [
        'Dashboards' => [
            'dashboard.view'      => 'Open dashboards shared with you',
            'dashboard.create'    => 'Create a dashboard',
            'dashboard.edit'      => 'Edit a dashboard you can manage',
            'dashboard.delete'    => 'Delete a dashboard',
            'dashboard.share'     => 'Share a dashboard with colleagues',
            'dashboard.publish'   => 'Publish a draft so others see the change',
        ],
        'Metrics' => [
            'metric.view'   => 'Browse the metric catalogue',
            'metric.manage' => 'Define and edit custom KPIs',
        ],
        'Analysis' => [
            'forecast.view'  => 'View forecasts',
            'anomaly.view'   => 'View business exceptions',
            'anomaly.review' => 'Acknowledge, dismiss and reopen exceptions',
            'ai.use'         => 'Ask Insights questions and request dashboard proposals',
        ],
        'Reports' => [
            'report.view'   => 'View saved reports',
            'report.manage' => 'Create and edit report definitions',
            'report.export' => 'Download a report as PDF, CSV or XLSX',
        ],
        'Administration' => [
            'source.view'     => 'See data-source status and capabilities',
            'settings.manage' => 'Change Insights settings for this company',
            'access.manage'   => 'Manage Insights permission profiles',
        ],
    ];

    /**
     * What a person with no profile at all may do.
     *
     * Deliberately not empty. Insights is a self-serve reading product, and a
     * company member who can already open Books should not be met by a wall of
     * refusals because nobody has been to Settings yet.
     *
     * WHAT IS IN IT: reading, and building a dashboard OF YOUR OWN. Creating,
     * editing and deleting are here because DashboardService decides WHICH
     * dashboard each of those may touch — you manage what you own, and a board
     * somebody shared with you read-only stays read-only however these are set.
     *
     * WHAT IS DELIBERATELY NOT IN IT: sharing, publishing to colleagues,
     * defining a company-wide KPI, exporting, asking the model (which costs
     * money on somebody's account), reviewing exceptions on the firm's behalf,
     * and administration. Each of those does something other people see, or
     * something that leaves the building.
     *
     * Nothing in this set grants business data. What a person can actually see
     * is decided by Books and Inventory, against their own account.
     *
     * @var list<string>
     */
    public const BASELINE = [
        'dashboard.view',
        'dashboard.create',
        'dashboard.edit',
        'dashboard.delete',
        'metric.view',
        'forecast.view',
        'anomaly.view',
        'report.view',
        'source.view',
    ];

    /** @var array<string, list<string>> */
    private static array $cache = [];

    public static function assert(Context $ctx, Auth $auth, string $permission): void
    {
        if (!self::allows($ctx, $auth, $permission)) {
            Http::forbidden('You do not have permission to ' . self::describe($permission) . '.');
        }
    }

    public static function allows(Context $ctx, Auth $auth, string $permission): bool
    {
        if ($auth->isService()) {
            return true;
        }
        if ($auth->ownsCompany($ctx->cmpId)) {
            return true;
        }

        return in_array($permission, self::granted($ctx, $auth), true);
    }

    /** @return list<string> */
    public static function granted(Context $ctx, Auth $auth): array
    {
        $key = $ctx->cmpId . ':' . $auth->uuid;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        if ($auth->isService() || $auth->ownsCompany($ctx->cmpId)) {
            return self::$cache[$key] = self::all();
        }

        try {
            $rows = Db::all(
                'SELECT p.permissions
                   FROM ' . self::TABLE_ASSIGNMENTS . ' a
                   JOIN ' . self::TABLE_PROFILES . ' p ON p.profile_id = a.profile_id
                  WHERE a.cmp_id = :cmp AND a.user_uuid = :uuid AND p.is_active = TRUE',
                ['cmp' => $ctx->cmpId, 'uuid' => $auth->uuid],
            );
        } catch (\Throwable $e) {
            error_log('[insights][permissions] lookup failed: ' . $e->getMessage());

            // A database hiccup must not silently promote anyone. Baseline is
            // the read-only floor, and it grants no data by itself.
            return self::$cache[$key] = self::BASELINE;
        }

        if ($rows === []) {
            return self::$cache[$key] = self::BASELINE;
        }

        $granted = [];
        foreach (self::BASELINE as $permission) {
            $granted[$permission] = true;
        }
        foreach ($rows as $row) {
            foreach (Db::jsonColumn($row['permissions'] ?? null) as $permission) {
                if (is_string($permission) && self::exists($permission)) {
                    $granted[$permission] = true;
                }
            }
        }

        return self::$cache[$key] = array_keys($granted);
    }

    /**
     * Drop the memoised grants for a user.
     *
     * The cache is per-request, which is right for reads. But an endpoint that
     * CHANGES somebody's profile and then reports the result would answer from
     * the grants it read before the change.
     */
    public static function forget(?Context $ctx = null, ?Auth $auth = null): void
    {
        if ($ctx === null || $auth === null) {
            self::$cache = [];

            return;
        }

        unset(self::$cache[$ctx->cmpId . ':' . $auth->uuid]);
    }

    /**
     * The permissions this caller may hand to somebody else.
     *
     * A company owner may grant anything. Anybody else may grant only what they
     * themselves hold — otherwise `access.manage` is not a permission, it is a
     * route to every other permission.
     *
     * @return list<string>
     */
    public static function grantable(Context $ctx, Auth $auth): array
    {
        if ($auth->isService() || $auth->ownsCompany($ctx->cmpId)) {
            return self::all();
        }

        return self::granted($ctx, $auth);
    }

    /** @return list<string> */
    public static function all(): array
    {
        $out = [];
        foreach (self::CATALOG as $group) {
            foreach (array_keys($group) as $permission) {
                $out[] = $permission;
            }
        }

        return $out;
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    private static function describe(string $permission): string
    {
        foreach (self::CATALOG as $group) {
            if (isset($group[$permission])) {
                return strtolower($group[$permission]);
            }
        }

        return 'do that';
    }
}
