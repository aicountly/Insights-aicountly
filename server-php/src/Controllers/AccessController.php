<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Insights permission profiles, and who holds them.
 *
 * THE GRANTABLE SET IS NOT THE WHOLE CATALOGUE. A company owner may grant
 * anything; anybody else may grant only what they themselves hold. Otherwise
 * `access.manage` is not a permission, it is a route to every other permission,
 * and one profile edit undoes the rest of the model.
 */
final class AccessController extends Controller
{
    public static function profiles(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $rows = Db::all(
            'SELECT p.*, (SELECT COUNT(*) FROM ' . Permissions::TABLE_ASSIGNMENTS . ' a WHERE a.profile_id = p.profile_id) AS member_count
               FROM ' . Permissions::TABLE_PROFILES . ' p
              WHERE p.cmp_id = :cmp
              ORDER BY p.is_active DESC, p.profile_name',
            ['cmp' => $ctx->cmpId],
        );

        Http::data([
            'profiles'  => array_map(static fn (array $row) => [
                'id'           => (int) $row['profile_id'],
                'name'         => $row['profile_name'],
                'description'  => $row['description'],
                'permissions'  => array_values(array_filter(Db::jsonColumn($row['permissions']), 'is_string')),
                'is_system'    => (bool) $row['is_system'],
                'is_active'    => (bool) $row['is_active'],
                'member_count' => (int) $row['member_count'],
            ], $rows),
            'catalog'   => Permissions::CATALOG,
            'grantable' => Permissions::grantable($ctx, $auth),
            'baseline'  => Permissions::BASELINE,
        ]);
    }

    public static function createProfile(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $body = Http::body();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            Http::validationFailed('Give the profile a name.', ['field' => 'name']);
        }

        $permissions = self::permissions($ctx, $auth, $body['permissions'] ?? []);

        $profileId = (int) Db::insert(Permissions::TABLE_PROFILES, [
            'cmp_id'       => $ctx->cmpId,
            'profile_name' => mb_substr($name, 0, 80),
            'description'  => mb_substr(trim((string) ($body['description'] ?? '')), 0, 300),
            'permissions'  => Db::json($permissions),
            'created_by'   => $auth->uuid,
        ], 'profile_id');

        Permissions::forget();

        Http::data(['id' => $profileId, 'name' => $name, 'permissions' => $permissions], 201);
    }

    public static function updateProfile(string $profileId): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $row = Db::first(
            'SELECT * FROM ' . Permissions::TABLE_PROFILES . ' WHERE cmp_id = :cmp AND profile_id = :id',
            ['cmp' => $ctx->cmpId, 'id' => (int) $profileId],
        );
        if ($row === null) {
            Http::notFound('There is no such profile in this company.');
        }
        if ((bool) $row['is_system']) {
            Http::forbidden('That is a built-in profile and cannot be edited.');
        }

        $body = Http::body();
        $values = ['updated_at' => gmdate('c')];

        if (array_key_exists('name', $body)) {
            $values['profile_name'] = mb_substr(trim((string) $body['name']), 0, 80);
        }
        if (array_key_exists('description', $body)) {
            $values['description'] = mb_substr(trim((string) $body['description']), 0, 300);
        }
        if (array_key_exists('permissions', $body)) {
            $values['permissions'] = Db::json(self::permissions($ctx, $auth, $body['permissions']));
        }
        if (array_key_exists('is_active', $body)) {
            $values['is_active'] = (bool) $body['is_active'];
        }

        Db::update(Permissions::TABLE_PROFILES, $values, ['profile_id' => (int) $row['profile_id']]);
        Permissions::forget();

        Http::data(['updated' => true]);
    }

    public static function members(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $rows = Db::all(
            'SELECT a.user_uuid, a.assigned_by, a.created_at, p.profile_id, p.profile_name
               FROM ' . Permissions::TABLE_ASSIGNMENTS . ' a
               JOIN ' . Permissions::TABLE_PROFILES . ' p ON p.profile_id = a.profile_id
              WHERE a.cmp_id = :cmp
              ORDER BY a.created_at DESC',
            ['cmp' => $ctx->cmpId],
        );

        Http::data([
            'members' => $rows,
            'note'    => 'Somebody with no profile here still gets the read-only baseline. What they can actually see is decided by their access in Smart Books and Inventory, not here.',
        ]);
    }

    public static function assign(string $userUuid): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $profileId = Http::intParam('profile_id');
        if ($profileId === null || $profileId <= 0) {
            Http::validationFailed('Which profile?', ['field' => 'profile_id']);
        }

        $profile = Db::first(
            'SELECT profile_id, permissions FROM ' . Permissions::TABLE_PROFILES . ' WHERE cmp_id = :cmp AND profile_id = :id AND is_active',
            ['cmp' => $ctx->cmpId, 'id' => $profileId],
        );
        if ($profile === null) {
            Http::notFound('There is no such active profile in this company.');
        }

        // Somebody cannot hand out a profile carrying more than they hold.
        $grantable = Permissions::grantable($ctx, $auth);
        $carried = array_values(array_filter(Db::jsonColumn($profile['permissions']), 'is_string'));
        $excess = array_values(array_diff($carried, $grantable, Permissions::BASELINE));
        if ($excess !== []) {
            Http::forbidden('That profile grants ' . implode(', ', $excess) . ', which you do not hold yourself.');
        }

        Db::run(
            'INSERT INTO ' . Permissions::TABLE_ASSIGNMENTS . ' (cmp_id, user_uuid, profile_id, assigned_by)
             VALUES (:cmp, :uuid, :profile, :by)
             ON CONFLICT (cmp_id, user_uuid, profile_id) DO NOTHING',
            ['cmp' => $ctx->cmpId, 'uuid' => mb_substr(trim($userUuid), 0, 64), 'profile' => $profileId, 'by' => $auth->uuid],
        );

        Permissions::forget();

        Http::data(['assigned' => true]);
    }

    public static function unassign(string $userUuid): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $profileId = Http::intParam('profile_id');
        $params = ['cmp' => $ctx->cmpId, 'uuid' => mb_substr(trim($userUuid), 0, 64)];
        $sql = 'DELETE FROM ' . Permissions::TABLE_ASSIGNMENTS . ' WHERE cmp_id = :cmp AND user_uuid = :uuid';

        if ($profileId !== null && $profileId > 0) {
            $sql .= ' AND profile_id = :profile';
            $params['profile'] = $profileId;
        }

        Db::run($sql, $params);
        Permissions::forget();

        Http::data(['removed' => true]);
    }

    /**
     * @param mixed $input
     * @return list<string>
     */
    private static function permissions(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth, mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $grantable = Permissions::grantable($ctx, $auth);
        $out = [];
        $refused = [];

        foreach ($input as $permission) {
            if (!is_string($permission) || !Permissions::exists($permission)) {
                continue;
            }
            if (!in_array($permission, $grantable, true)) {
                $refused[] = $permission;
                continue;
            }
            $out[] = $permission;
        }

        if ($refused !== []) {
            Http::forbidden('You cannot grant ' . implode(', ', $refused) . ' because you do not hold it yourself.');
        }

        return array_values(array_unique($out));
    }
}
