<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Ids;

/**
 * Dashboards: create, edit, version, publish, share, delete.
 *
 * THREE THINGS THIS CLASS IS CAREFUL ABOUT, and each one is a bug somebody
 * would otherwise find in production:
 *
 *  1. ACCESS IS RESOLVED PER READ, NEVER CACHED ONTO THE ROW. `access()` asks
 *     the same question every time: what may THIS person do with THIS
 *     dashboard, in THIS company. A dashboard carries no list of who may open
 *     it — the shares do — so revoking a share takes effect on the next
 *     request rather than the next deploy.
 *
 *  2. RESTORING A REVISION RESTORES LAYOUT ONLY. The snapshot deliberately
 *     contains no shares. Rolling a dashboard back to March must not re-admit
 *     somebody who was removed in April, and the only way to be sure of that is
 *     for the old permissions never to have been written down.
 *
 *  3. SAVES ARE CHECKED AGAINST THE REVISION THE EDITOR READ. Two people with
 *     edit rights on one board is normal; the second save silently discarding
 *     the first is not. A stale save is a 409 naming who else changed it.
 */
final class DashboardService
{
    public const TABLE = 'insights_dashboards';
    public const WIDGETS = 'insights_dashboard_widgets';
    public const VERSIONS = 'insights_dashboard_versions';
    public const SHARES = 'insights_dashboard_shares';
    public const FAVOURITES = 'insights_dashboard_favourites';

    /** Revisions kept per dashboard. Older ones are pruned on save. */
    public const MAX_VERSIONS = 30;

    public const ACCESS_NONE = 'none';
    public const ACCESS_VIEW = 'view';
    public const ACCESS_EDIT = 'edit';
    public const ACCESS_MANAGE = 'manage';

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    /**
     * Keep only the parameters a statement actually names.
     *
     * The visibility predicate above collapses to TRUE for a company owner,
     * which removes the ONLY mention of :uuid from the count query — and PDO,
     * with emulated prepares off, rejects a bound parameter the statement does
     * not use. It fails as SQLSTATE[HY093], which surfaces as a 503 "database
     * unreachable", so the symptom says nothing about the cause.
     *
     * Filtering here lets the predicate keep changing shape without every call
     * site remembering which binds the current shape dropped. A parameter the
     * SQL names but nobody supplied still fails, which is the direction worth
     * failing in.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function bound(string $sql, array $params): array
    {
        return array_filter(
            $params,
            static fn (string $name): bool => preg_match('/:' . preg_quote($name, '/') . '\b/', $sql) === 1,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The dashboards this person can open, in one of three groupings.
     *
     * @param string $scope mine | shared | team | all
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function library(string $scope = 'all', string $search = '', int $limit = 50, int $offset = 0, string $sort = 'updated_at', string $order = 'DESC'): array
    {
        $params = ['cmp' => $this->ctx->cmpId, 'uuid' => $this->auth->uuid];

        // The access predicate, written once. A dashboard is listable when the
        // caller owns it, the company shares it with everyone, or a share names
        // them. A company owner sees everything in their own company.
        $visible = '(d.owner_uuid = :uuid
                     OR d.visibility = \'organisation\'
                     OR EXISTS (
                         SELECT 1 FROM ' . self::SHARES . ' s
                          WHERE s.dashboard_id = d.dashboard_id
                            AND ((s.subject_type = \'user\' AND s.subject_id = :uuid)
                              OR  s.subject_type = \'company\')
                     ))';

        if ($this->auth->ownsCompany($this->ctx->cmpId) || $this->auth->isService()) {
            $visible = 'TRUE';
        }

        $where = ['d.cmp_id = :cmp', 'NOT d.is_archived', $visible];

        switch ($scope) {
            case 'mine':
                $where[] = 'd.owner_uuid = :uuid';
                break;
            case 'shared':
                $where[] = 'd.owner_uuid <> :uuid';
                $where[] = 'EXISTS (SELECT 1 FROM ' . self::SHARES . ' s2 WHERE s2.dashboard_id = d.dashboard_id AND s2.subject_type = \'user\' AND s2.subject_id = :uuid)';
                break;
            case 'team':
                $where[] = "d.visibility IN ('team', 'organisation')";
                break;
        }

        if ($search !== '') {
            // ILIKE with a bound parameter: the term is never concatenated into
            // the SQL, and the wildcards are ours rather than the user's.
            $where[] = '(d.title ILIKE :q OR d.description ILIKE :q)';
            $params['q'] = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
        }

        $sortable = ['updated_at' => 'd.updated_at', 'title' => 'd.title', 'created_at' => 'd.created_at'];
        $sortColumn = $sortable[$sort] ?? 'd.updated_at';
        $direction = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $clause = implode(' AND ', $where);

        $countSql = 'SELECT COUNT(*) FROM ' . self::TABLE . ' d WHERE ' . $clause;
        $total = (int) Db::scalar($countSql, self::bound($countSql, $params));

        $rowsSql = 'SELECT d.*,
                    (SELECT COUNT(*) FROM ' . self::WIDGETS . ' w WHERE w.dashboard_id = d.dashboard_id) AS widget_count,
                    (SELECT COUNT(*) FROM ' . self::SHARES . ' s3 WHERE s3.dashboard_id = d.dashboard_id) AS share_count,
                    EXISTS (SELECT 1 FROM ' . self::FAVOURITES . ' f WHERE f.dashboard_id = d.dashboard_id AND f.user_uuid = :uuid) AS is_favourite
               FROM ' . self::TABLE . ' d
              WHERE ' . $clause . '
              ORDER BY is_favourite DESC, ' . $sortColumn . ' ' . $direction . '
              LIMIT :limit OFFSET :offset';

        $rows = Db::all(
            $rowsSql,
            self::bound($rowsSql, $params + ['limit' => max(1, min(200, $limit)), 'offset' => max(0, $offset)]),
        );

        return [
            'rows'  => array_map(fn (array $row) => $this->present($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * One dashboard with its widgets, or null when it is not this caller's to see.
     *
     * Returning null rather than throwing lets the caller decide between 404
     * and 403 — and they are the same answer here on purpose: a dashboard the
     * caller may not open should not be distinguishable from one that does not
     * exist, or the ids become an enumeration oracle.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $publicId, bool $published = false): ?array
    {
        $row = $this->row($publicId);
        if ($row === null) {
            return null;
        }

        $access = $this->access($row);
        if ($access === self::ACCESS_NONE) {
            return null;
        }

        $dashboard = $this->present($row);
        $dashboard['access'] = $access;
        $dashboard['widgets'] = $this->widgets((int) $row['dashboard_id']);
        $dashboard['shares'] = $access === self::ACCESS_MANAGE ? $this->shares((int) $row['dashboard_id']) : [];

        if ($published) {
            $snapshot = $this->publishedSnapshot((int) $row['dashboard_id']);
            if ($snapshot !== null) {
                $dashboard['widgets'] = $snapshot['widgets'];
                $dashboard['settings'] = $snapshot['dashboard']['settings'] ?? $dashboard['settings'];
                $dashboard['viewing'] = 'published';
            } else {
                $dashboard['viewing'] = 'draft';
                $dashboard['notice'] = 'This dashboard has not been published yet, so you are looking at the working copy.';
            }
        } else {
            $dashboard['viewing'] = 'draft';
        }

        return $dashboard;
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'dashboard.create');

        $title = $this->title($input['title'] ?? null);
        $publicId = Ids::public('dash');

        return Db::transaction(function () use ($input, $title, $publicId): array {
            $dashboardId = (int) Db::insert(self::TABLE, [
                'public_id'    => $publicId,
                'cmp_id'       => $this->ctx->cmpId,
                'owner_uuid'   => $this->auth->uuid,
                'title'        => $title,
                'description'  => $this->text($input['description'] ?? null, 600),
                'visibility'   => $this->visibility($input['visibility'] ?? null),
                'tags'         => Db::json($this->tags($input['tags'] ?? null)),
                'settings'     => Db::json(WidgetSchema::dashboardSettings($input['settings'] ?? null)),
                'template_key' => is_string($input['template_key'] ?? null) ? mb_substr($input['template_key'], 0, 64) : null,
                'updated_by'   => $this->auth->uuid,
            ], 'dashboard_id');

            $widgets = is_array($input['widgets'] ?? null) ? $input['widgets'] : [];
            $this->replaceWidgets($dashboardId, $widgets);
            $this->snapshot($dashboardId, 1, 'Created', false);
            $this->audit('dashboard.created', 'dashboard', $publicId, ['title' => $title]);

            return (array) $this->find($publicId);
        });
    }

    /**
     * Save the working copy.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(string $publicId, array $input): array
    {
        $row = $this->requireAccess($publicId, self::ACCESS_EDIT);
        Permissions::assert($this->ctx, $this->auth, 'dashboard.edit');

        $expected = isset($input['revision']) ? (int) $input['revision'] : null;
        $current = (int) $row['revision'];

        if ($expected !== null && $expected !== $current) {
            // Optimistic concurrency. The caller is told what they were working
            // from, what is there now, and who put it there — enough to decide
            // whether to reload or to copy their changes across.
            Http::conflict(
                'Somebody else saved this dashboard while you were editing it. Reload to see their version before saving yours.',
                [
                    'expected_revision' => $expected,
                    'current_revision'  => $current,
                    'updated_by'        => $row['updated_by'],
                    'updated_at'        => $row['updated_at'],
                ],
            );
        }

        $dashboardId = (int) $row['dashboard_id'];
        $next = $current + 1;

        return Db::transaction(function () use ($row, $input, $dashboardId, $next, $publicId): array {
            $values = ['revision' => $next, 'updated_at' => gmdate('c'), 'updated_by' => $this->auth->uuid];

            if (array_key_exists('title', $input)) {
                $values['title'] = $this->title($input['title']);
            }
            if (array_key_exists('description', $input)) {
                $values['description'] = $this->text($input['description'], 600);
            }
            if (array_key_exists('tags', $input)) {
                $values['tags'] = Db::json($this->tags($input['tags']));
            }
            if (array_key_exists('settings', $input)) {
                $values['settings'] = Db::json(WidgetSchema::dashboardSettings($input['settings']));
            }
            // Visibility is a sharing decision, so it needs the sharing right
            // rather than the editing one.
            if (array_key_exists('visibility', $input) && $this->access($row) === self::ACCESS_MANAGE) {
                Permissions::assert($this->ctx, $this->auth, 'dashboard.share');
                $values['visibility'] = $this->visibility($input['visibility']);
            }

            Db::update(self::TABLE, $values, ['dashboard_id' => $dashboardId]);

            if (array_key_exists('widgets', $input)) {
                $this->replaceWidgets($dashboardId, is_array($input['widgets']) ? $input['widgets'] : []);
            }

            $this->snapshot($dashboardId, $next, $this->text($input['version_label'] ?? null, 120), false);
            $this->audit('dashboard.saved', 'dashboard', $publicId, ['revision' => $next]);

            return (array) $this->find($publicId);
        });
    }

    /**
     * Publish the working copy so viewers see it.
     *
     * @return array<string, mixed>
     */
    public function publish(string $publicId): array
    {
        $row = $this->requireAccess($publicId, self::ACCESS_EDIT);
        Permissions::assert($this->ctx, $this->auth, 'dashboard.publish');

        $dashboardId = (int) $row['dashboard_id'];
        $revision = (int) $row['revision'];

        return Db::transaction(function () use ($dashboardId, $revision, $publicId): array {
            // Mark the CURRENT revision published rather than taking a new
            // snapshot: publishing is a decision about a version that exists,
            // not a new version of its own.
            Db::run(
                'UPDATE ' . self::VERSIONS . ' SET is_published = (revision = :revision) WHERE dashboard_id = :dashboard',
                ['revision' => $revision, 'dashboard' => $dashboardId],
            );

            if ((int) Db::scalar('SELECT COUNT(*) FROM ' . self::VERSIONS . ' WHERE dashboard_id = :d AND revision = :r', ['d' => $dashboardId, 'r' => $revision]) === 0) {
                $this->snapshot($dashboardId, $revision, 'Published', true);
            }

            Db::update(self::TABLE, [
                'published_at' => gmdate('c'),
                'published_by' => $this->auth->uuid,
            ], ['dashboard_id' => $dashboardId]);

            $this->audit('dashboard.published', 'dashboard', $publicId, ['revision' => $revision]);

            return (array) $this->find($publicId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function duplicate(string $publicId, ?string $title = null): array
    {
        $row = $this->requireAccess($publicId, self::ACCESS_VIEW);
        Permissions::assert($this->ctx, $this->auth, 'dashboard.create');

        $source = (int) $row['dashboard_id'];
        $newPublicId = Ids::public('dash');

        return Db::transaction(function () use ($row, $source, $newPublicId, $title): array {
            $dashboardId = (int) Db::insert(self::TABLE, [
                'public_id'   => $newPublicId,
                'cmp_id'      => $this->ctx->cmpId,
                // The copy belongs to whoever made it, and starts PRIVATE. A
                // duplicate that inherited the original's shares would hand a
                // new dashboard to everybody who could see the old one, without
                // anybody deciding to.
                'owner_uuid'  => $this->auth->uuid,
                'title'       => $this->title($title ?? ($row['title'] . ' (copy)')),
                'description' => (string) $row['description'],
                'visibility'  => 'private',
                'tags'        => Db::json(Db::jsonColumn($row['tags'])),
                'settings'    => Db::json(Db::jsonColumn($row['settings'])),
                'template_key' => $row['template_key'],
                'updated_by'  => $this->auth->uuid,
            ], 'dashboard_id');

            foreach ($this->widgetRows($source) as $widget) {
                Db::insert(self::WIDGETS, [
                    'public_id'    => Ids::public('wid'),
                    'dashboard_id' => $dashboardId,
                    'cmp_id'       => $this->ctx->cmpId,
                    'widget_type'  => $widget['widget_type'],
                    'title'        => $widget['title'],
                    'description'  => $widget['description'],
                    'config'       => Db::json(Db::jsonColumn($widget['config'])),
                    'layout'       => Db::json(Db::jsonColumn($widget['layout'])),
                    'position'     => (int) $widget['position'],
                ], 'widget_id');
            }

            $this->snapshot($dashboardId, 1, 'Copied from ' . $row['title'], false);
            $this->audit('dashboard.duplicated', 'dashboard', $newPublicId, ['from' => $row['public_id']]);

            return (array) $this->find($newPublicId);
        });
    }

    public function delete(string $publicId): void
    {
        $row = $this->requireAccess($publicId, self::ACCESS_MANAGE);
        Permissions::assert($this->ctx, $this->auth, 'dashboard.delete');

        // Archived, not dropped. A dashboard somebody spent an afternoon on is
        // not worth losing to a misclick, and the row is small.
        Db::update(self::TABLE, [
            'is_archived' => true,
            'updated_at'  => gmdate('c'),
            'updated_by'  => $this->auth->uuid,
        ], ['dashboard_id' => (int) $row['dashboard_id']]);

        // The shares go immediately. An archived dashboard that could be
        // restored with its old audience is the same problem as restoring a
        // revision with old permissions.
        Db::delete(self::SHARES, ['dashboard_id' => (int) $row['dashboard_id']]);

        $this->audit('dashboard.deleted', 'dashboard', $publicId, []);
    }

    // -----------------------------------------------------------------------
    // Revisions
    // -----------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function versions(string $publicId): array
    {
        $row = $this->requireAccess($publicId, self::ACCESS_VIEW);

        $rows = Db::all(
            'SELECT public_id, revision, label, is_published, created_by, created_at
               FROM ' . self::VERSIONS . '
              WHERE dashboard_id = :d
              ORDER BY revision DESC
              LIMIT 50',
            ['d' => (int) $row['dashboard_id']],
        );

        return array_map(static fn (array $v) => [
            'id'           => $v['public_id'],
            'revision'     => (int) $v['revision'],
            'label'        => (string) $v['label'],
            'is_published' => (bool) $v['is_published'],
            'created_by'   => (string) $v['created_by'],
            'created_at'   => (string) $v['created_at'],
        ], $rows);
    }

    /**
     * Restore a revision's LAYOUT AND CONFIGURATION.
     *
     * Not its shares, and not its published state: the restore creates a new
     * revision like any other save, and the dashboard stays published at
     * whatever it was published at until somebody publishes again.
     *
     * @return array<string, mixed>
     */
    public function restore(string $publicId, int $revision): array
    {
        $row = $this->requireAccess($publicId, self::ACCESS_EDIT);
        Permissions::assert($this->ctx, $this->auth, 'dashboard.edit');

        $dashboardId = (int) $row['dashboard_id'];
        $version = Db::first(
            'SELECT * FROM ' . self::VERSIONS . ' WHERE dashboard_id = :d AND revision = :r',
            ['d' => $dashboardId, 'r' => $revision],
        );

        if ($version === null) {
            Http::notFound('There is no revision ' . $revision . ' of this dashboard.');
        }

        $snapshot = Db::jsonColumn($version['snapshot']);
        $next = (int) $row['revision'] + 1;

        return Db::transaction(function () use ($snapshot, $dashboardId, $next, $revision, $publicId): array {
            $dashboard = is_array($snapshot['dashboard'] ?? null) ? $snapshot['dashboard'] : [];

            Db::update(self::TABLE, [
                'title'       => $this->title($dashboard['title'] ?? null),
                'description' => $this->text($dashboard['description'] ?? null, 600),
                'tags'        => Db::json($this->tags($dashboard['tags'] ?? null)),
                'settings'    => Db::json(WidgetSchema::dashboardSettings($dashboard['settings'] ?? null)),
                // VISIBILITY IS NOT RESTORED. Nor are shares — they are not in
                // the snapshot at all. Restoring a layout must not restore an
                // audience.
                'revision'    => $next,
                'updated_at'  => gmdate('c'),
                'updated_by'  => $this->auth->uuid,
            ], ['dashboard_id' => $dashboardId]);

            $this->replaceWidgets($dashboardId, is_array($snapshot['widgets'] ?? null) ? $snapshot['widgets'] : []);
            $this->snapshot($dashboardId, $next, 'Restored revision ' . $revision, false);
            $this->audit('dashboard.restored', 'dashboard', $publicId, ['from_revision' => $revision, 'revision' => $next]);

            return (array) $this->find($publicId);
        });
    }

    // -----------------------------------------------------------------------
    // Sharing
    // -----------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function shares(int $dashboardId): array
    {
        $rows = Db::all(
            'SELECT public_id, subject_type, subject_id, permission, granted_by, created_at
               FROM ' . self::SHARES . ' WHERE dashboard_id = :d ORDER BY created_at',
            ['d' => $dashboardId],
        );

        return array_map(static fn (array $s) => [
            'id'           => $s['public_id'],
            'subject_type' => $s['subject_type'],
            'subject_id'   => $s['subject_id'],
            'permission'   => $s['permission'],
            'granted_by'   => $s['granted_by'],
            'created_at'   => $s['created_at'],
        ], $rows);
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    public function share(string $publicId, array $input): array
    {
        $row = $this->requireAccess($publicId, self::ACCESS_MANAGE);
        Permissions::assert($this->ctx, $this->auth, 'dashboard.share');

        $subjectType = (string) ($input['subject_type'] ?? 'user');
        if (!in_array($subjectType, ['user', 'company'], true)) {
            Http::validationFailed('Share with a person or with the whole company.', ['field' => 'subject_type']);
        }

        $subjectId = trim((string) ($input['subject_id'] ?? ''));
        if ($subjectType === 'company') {
            // Always THIS company. A share naming another tenant is not a share,
            // it is a cross-tenant grant, and there is no code path for it.
            $subjectId = (string) $this->ctx->cmpId;
        } elseif ($subjectId === '' || mb_strlen($subjectId) > 64) {
            Http::validationFailed('Give the portal id of the person to share with.', ['field' => 'subject_id']);
        }

        $permission = (string) ($input['permission'] ?? 'view');
        if (!in_array($permission, [self::ACCESS_VIEW, self::ACCESS_EDIT, self::ACCESS_MANAGE], true)) {
            Http::validationFailed('A share grants view, edit or manage.', ['field' => 'permission']);
        }

        $dashboardId = (int) $row['dashboard_id'];

        Db::run(
            'INSERT INTO ' . self::SHARES . ' (public_id, dashboard_id, cmp_id, subject_type, subject_id, permission, granted_by)
             VALUES (:pid, :d, :cmp, :st, :sid, :perm, :by)
             ON CONFLICT (dashboard_id, subject_type, subject_id)
             DO UPDATE SET permission = EXCLUDED.permission, granted_by = EXCLUDED.granted_by',
            [
                'pid'  => Ids::public('shr'),
                'd'    => $dashboardId,
                'cmp'  => $this->ctx->cmpId,
                'st'   => $subjectType,
                'sid'  => $subjectId,
                'perm' => $permission,
                'by'   => $this->auth->uuid,
            ],
        );

        $this->audit('dashboard.shared', 'dashboard', $publicId, [
            'subject_type' => $subjectType,
            'permission'   => $permission,
        ]);

        return $this->shares($dashboardId);
    }

    /** @return list<array<string, mixed>> */
    public function unshare(string $publicId, string $sharePublicId): array
    {
        $row = $this->requireAccess($publicId, self::ACCESS_MANAGE);
        Permissions::assert($this->ctx, $this->auth, 'dashboard.share');

        Db::run(
            'DELETE FROM ' . self::SHARES . ' WHERE dashboard_id = :d AND public_id = :pid',
            ['d' => (int) $row['dashboard_id'], 'pid' => $sharePublicId],
        );

        $this->audit('dashboard.unshared', 'dashboard', $publicId, []);

        return $this->shares((int) $row['dashboard_id']);
    }

    public function favourite(string $publicId, bool $on): void
    {
        $row = $this->requireAccess($publicId, self::ACCESS_VIEW);

        if ($on) {
            Db::run(
                'INSERT INTO ' . self::FAVOURITES . ' (dashboard_id, cmp_id, user_uuid) VALUES (:d, :cmp, :u)
                 ON CONFLICT (dashboard_id, user_uuid) DO NOTHING',
                ['d' => (int) $row['dashboard_id'], 'cmp' => $this->ctx->cmpId, 'u' => $this->auth->uuid],
            );

            return;
        }

        Db::delete(self::FAVOURITES, ['dashboard_id' => (int) $row['dashboard_id'], 'user_uuid' => $this->auth->uuid]);
    }

    // -----------------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------------

    /**
     * What this caller may do with this dashboard.
     *
     * @param array<string, mixed> $row
     */
    public function access(array $row): string
    {
        // Cross-tenant is not a permission question. A row from another company
        // is invisible, whoever is asking.
        if ((int) $row['cmp_id'] !== $this->ctx->cmpId) {
            return self::ACCESS_NONE;
        }

        if ($this->auth->isService()) {
            return self::ACCESS_MANAGE;
        }
        if ((string) $row['owner_uuid'] === $this->auth->uuid) {
            return self::ACCESS_MANAGE;
        }
        if ($this->auth->ownsCompany($this->ctx->cmpId)) {
            return self::ACCESS_MANAGE;
        }

        $share = Db::first(
            'SELECT permission FROM ' . self::SHARES . '
              WHERE dashboard_id = :d
                AND ((subject_type = \'user\' AND subject_id = :uuid) OR (subject_type = \'company\' AND subject_id = :cmp))
              ORDER BY CASE permission WHEN \'manage\' THEN 3 WHEN \'edit\' THEN 2 ELSE 1 END DESC
              LIMIT 1',
            ['d' => (int) $row['dashboard_id'], 'uuid' => $this->auth->uuid, 'cmp' => (string) $this->ctx->cmpId],
        );

        if ($share !== null) {
            return (string) $share['permission'];
        }

        if ((string) $row['visibility'] === 'organisation') {
            return self::ACCESS_VIEW;
        }

        return self::ACCESS_NONE;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireAccess(string $publicId, string $needed): array
    {
        $row = $this->row($publicId);
        if ($row === null) {
            Http::notFound('That dashboard does not exist, or is not shared with you.');
        }

        $rank = [self::ACCESS_NONE => 0, self::ACCESS_VIEW => 1, self::ACCESS_EDIT => 2, self::ACCESS_MANAGE => 3];
        $have = $this->access($row);

        if ($rank[$have] < $rank[$needed]) {
            if ($have === self::ACCESS_NONE) {
                // Same answer as "does not exist", so an id cannot be probed.
                Http::notFound('That dashboard does not exist, or is not shared with you.');
            }
            Http::forbidden('You have ' . $have . ' access to this dashboard, and this needs ' . $needed . '.');
        }

        return $row;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function row(string $publicId): ?array
    {
        if (!Ids::isValid($publicId, 'dash')) {
            return null;
        }

        return Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE cmp_id = :cmp AND public_id = :pid AND NOT is_archived',
            ['cmp' => $this->ctx->cmpId, 'pid' => $publicId],
        );
    }

    /** @return list<array<string, mixed>> */
    private function widgetRows(int $dashboardId): array
    {
        return Db::all(
            'SELECT * FROM ' . self::WIDGETS . ' WHERE dashboard_id = :d ORDER BY position, widget_id',
            ['d' => $dashboardId],
        );
    }

    /** @return list<array<string, mixed>> */
    private function widgets(int $dashboardId): array
    {
        return array_map(static fn (array $w) => [
            'id'          => $w['public_id'],
            'widget_type' => $w['widget_type'],
            'title'       => $w['title'],
            'description' => $w['description'],
            'config'      => Db::jsonColumn($w['config']),
            'layout'      => Db::jsonColumn($w['layout']),
            'position'    => (int) $w['position'],
        ], $this->widgetRows($dashboardId));
    }

    /**
     * @param list<array<string, mixed>> $widgets
     */
    private function replaceWidgets(int $dashboardId, array $widgets): void
    {
        if (count($widgets) > WidgetSchema::MAX_WIDGETS) {
            Http::validationFailed(
                'A dashboard holds up to ' . WidgetSchema::MAX_WIDGETS . ' widgets. Split this into two boards.',
                ['field' => 'widgets', 'limit' => WidgetSchema::MAX_WIDGETS],
            );
        }

        $clean = [];
        foreach (array_values($widgets) as $index => $widget) {
            if (!is_array($widget)) {
                continue;
            }
            try {
                $clean[] = WidgetSchema::widget($widget, $this->ctx) + ['position' => $index];
            } catch (WidgetSchemaError $e) {
                Http::validationFailed($e->getMessage(), [
                    'field'  => $e->field,
                    'widget' => $index,
                    'title'  => is_string($widget['title'] ?? null) ? mb_substr($widget['title'], 0, 60) : null,
                ] + $e->details);
            }
        }

        // Replace wholesale. The editor sends the whole board, and diffing
        // widget by widget would leave an orphan the first time a client
        // dropped one from the array.
        Db::delete(self::WIDGETS, ['dashboard_id' => $dashboardId]);

        foreach ($clean as $widget) {
            Db::insert(self::WIDGETS, [
                'public_id'    => Ids::public('wid'),
                'dashboard_id' => $dashboardId,
                'cmp_id'       => $this->ctx->cmpId,
                'widget_type'  => $widget['widget_type'],
                'title'        => $widget['title'],
                'description'  => $widget['description'],
                'config'       => Db::json($widget['config']),
                'layout'       => Db::json($widget['layout']),
                'position'     => $widget['position'],
            ], 'widget_id');
        }
    }

    private function snapshot(int $dashboardId, int $revision, string $label, bool $published): void
    {
        $dashboard = Db::first('SELECT * FROM ' . self::TABLE . ' WHERE dashboard_id = :d', ['d' => $dashboardId]);
        if ($dashboard === null) {
            return;
        }

        // Deliberately narrow. No shares, no visibility, no owner — a snapshot
        // is a layout, and restoring it must not restore an audience.
        $snapshot = [
            'dashboard' => [
                'title'       => $dashboard['title'],
                'description' => $dashboard['description'],
                'tags'        => Db::jsonColumn($dashboard['tags']),
                'settings'    => Db::jsonColumn($dashboard['settings']),
            ],
            'widgets' => $this->widgets($dashboardId),
        ];

        Db::run(
            'INSERT INTO ' . self::VERSIONS . ' (public_id, dashboard_id, cmp_id, revision, label, snapshot, is_published, created_by)
             VALUES (:pid, :d, :cmp, :rev, :label, :snap, :pub, :by)
             ON CONFLICT (dashboard_id, revision) DO UPDATE SET snapshot = EXCLUDED.snapshot, label = EXCLUDED.label',
            [
                'pid'   => Ids::public('ver'),
                'd'     => $dashboardId,
                'cmp'   => $this->ctx->cmpId,
                'rev'   => $revision,
                'label' => $label,
                'snap'  => Db::json($snapshot),
                'pub'   => $published ? 'true' : 'false',
                'by'    => $this->auth->uuid,
            ],
        );

        // Keep the history bounded, but never prune the published one — it is
        // what viewers are looking at.
        Db::run(
            'DELETE FROM ' . self::VERSIONS . '
              WHERE dashboard_id = :d
                AND NOT is_published
                AND revision NOT IN (
                    SELECT revision FROM ' . self::VERSIONS . '
                     WHERE dashboard_id = :d ORDER BY revision DESC LIMIT :keep
                )',
            ['d' => $dashboardId, 'keep' => self::MAX_VERSIONS],
        );
    }

    /** @return array{dashboard:array<string,mixed>, widgets:list<array<string,mixed>>}|null */
    private function publishedSnapshot(int $dashboardId): ?array
    {
        $row = Db::first(
            'SELECT snapshot FROM ' . self::VERSIONS . ' WHERE dashboard_id = :d AND is_published ORDER BY revision DESC LIMIT 1',
            ['d' => $dashboardId],
        );

        if ($row === null) {
            return null;
        }

        $snapshot = Db::jsonColumn($row['snapshot']);

        return [
            'dashboard' => is_array($snapshot['dashboard'] ?? null) ? $snapshot['dashboard'] : [],
            'widgets'   => is_array($snapshot['widgets'] ?? null) ? array_values($snapshot['widgets']) : [],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id'            => $row['public_id'],
            'title'         => $row['title'],
            'description'   => $row['description'],
            'visibility'    => $row['visibility'],
            'tags'          => array_values(array_filter(Db::jsonColumn($row['tags']), 'is_string')),
            'settings'      => Db::jsonColumn($row['settings']),
            'revision'      => (int) $row['revision'],
            'owner_uuid'    => $row['owner_uuid'],
            'is_owner'      => (string) $row['owner_uuid'] === $this->auth->uuid,
            'is_favourite'  => (bool) ($row['is_favourite'] ?? false),
            'widget_count'  => isset($row['widget_count']) ? (int) $row['widget_count'] : null,
            'share_count'   => isset($row['share_count']) ? (int) $row['share_count'] : null,
            'template_key'  => $row['template_key'],
            'published_at'  => $row['published_at'],
            'created_at'    => $row['created_at'],
            'updated_at'    => $row['updated_at'],
            'updated_by'    => $row['updated_by'],
        ];
    }

    private function title(mixed $value): string
    {
        $title = trim((string) ($value ?? ''));
        if ($title === '') {
            Http::validationFailed('Give the dashboard a name.', ['field' => 'title']);
        }

        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title), 0, 160);
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', (string) ($value ?? ''))), 0, $max);
    }

    /** @return list<string> */
    private function tags(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $clean = mb_substr(trim((string) preg_replace('/[^\p{L}\p{N} _-]+/u', '', $tag)), 0, 32);
            if ($clean !== '' && !in_array($clean, $out, true)) {
                $out[] = $clean;
            }
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    private function visibility(mixed $value): string
    {
        $visibility = (string) ($value ?? 'private');

        return in_array($visibility, ['private', 'team', 'organisation'], true) ? $visibility : 'private';
    }

    /** @param array<string, mixed> $detail */
    private function audit(string $action, string $objectType, string $objectId, array $detail): void
    {
        try {
            Db::insert('insights_audit_events', [
                'cmp_id'      => $this->ctx->cmpId,
                'user_uuid'   => $this->auth->uuid,
                'action'      => $action,
                'object_type' => $objectType,
                'object_id'   => $objectId,
                'detail'      => Db::json($detail),
            ], 'event_id');
        } catch (\Throwable $e) {
            // An audit failure must not fail the action it is recording.
            error_log('[insights][audit] ' . $action . ' not recorded: ' . $e->getMessage());
        }
    }
}
