<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Ai\Budget;
use Aicountly\Api\Ai\ConsoleCredentials;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Period;

/**
 * Session, permissions and preferences.
 *
 * `session` is the first call the app makes after a company is picked, and the
 * answer shapes the whole UI: what the person may do, whether AI is configured,
 * and their display preferences. It is deliberately one call — three would be
 * three round trips before anything is drawn.
 */
final class SettingsController extends Controller
{
    public static function session(): void
    {
        [$auth, $ctx] = self::enter();

        Http::data([
            'uuid'         => $auth->uuid,
            'display_name' => $auth->displayName(),
            'kind'         => $auth->isService() ? 'service' : 'user',
            'is_owner'     => $auth->ownsCompany($ctx->cmpId),
            'context'      => $ctx->asQuery(),
            'permissions'  => Permissions::granted($ctx, $auth),
            'preferences'  => self::preferences($ctx, $auth),
            // What the app may say about AI, with nothing secret in it. The
            // admin hint is withheld from somebody who could not act on it.
            'ai'           => self::aiStatus($ctx, $auth),
        ]);
    }

    public static function permissionsCatalog(): void
    {
        [$auth, $ctx] = self::enter();

        Http::data([
            'catalog'   => Permissions::CATALOG,
            'granted'   => Permissions::granted($ctx, $auth),
            'grantable' => Permissions::grantable($ctx, $auth),
            'baseline'  => Permissions::BASELINE,
        ]);
    }

    public static function showPreferences(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data(self::preferences($ctx, $auth));
    }

    public static function updatePreferences(): void
    {
        [$auth, $ctx] = self::enter();

        $body = Http::body();
        $preferences = [
            'timezone'        => self::timezone($body['timezone'] ?? null),
            'number_style'    => in_array((string) ($body['number_style'] ?? ''), ['indian', 'western'], true) ? (string) $body['number_style'] : 'indian',
            'default_preset'  => in_array((string) ($body['default_preset'] ?? ''), Period::PRESETS, true) ? (string) $body['default_preset'] : 'this_month',
            'default_grain'   => in_array((string) ($body['default_grain'] ?? ''), Period::GRAINS, true) ? (string) $body['default_grain'] : 'month',
            'default_compare' => in_array((string) ($body['default_compare'] ?? ''), Period::COMPARISONS, true) ? (string) $body['default_compare'] : 'previous_period',
            'density'         => in_array((string) ($body['density'] ?? ''), ['comfortable', 'compact'], true) ? (string) $body['density'] : 'comfortable',
            'default_dashboard' => is_string($body['default_dashboard'] ?? null) ? mb_substr($body['default_dashboard'], 0, 40) : null,
        ];

        Db::run(
            'INSERT INTO insights_user_preferences (cmp_id, user_uuid, preferences)
             VALUES (:cmp, :uuid, :prefs)
             ON CONFLICT (cmp_id, user_uuid) DO UPDATE SET preferences = EXCLUDED.preferences, updated_at = NOW()',
            ['cmp' => $ctx->cmpId, 'uuid' => $auth->uuid, 'prefs' => Db::json($preferences)],
        );

        Http::data($preferences);
    }

    public static function showCompanySettings(): void
    {
        [$auth, $ctx] = self::enter();

        $row = Db::first('SELECT settings FROM insights_company_settings WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);
        $settings = $row === null ? [] : Db::jsonColumn($row['settings']);

        Http::data([
            'settings' => $settings + self::defaultCompanySettings(),
            'can_edit' => Permissions::allows($ctx, $auth, 'settings.manage'),
            'ai'       => self::aiStatus($ctx, $auth),
        ]);
    }

    public static function updateCompanySettings(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'settings.manage');

        $body = Http::body();
        $settings = [
            'default_template'  => is_string($body['default_template'] ?? null) ? mb_substr($body['default_template'], 0, 64) : null,
            'allow_org_sharing' => (bool) ($body['allow_org_sharing'] ?? true),
            'allow_ai'          => (bool) ($body['allow_ai'] ?? true),
            'currency'          => preg_match('/^[A-Z]{3}$/', (string) ($body['currency'] ?? 'INR')) === 1 ? (string) $body['currency'] : 'INR',
        ];

        Db::run(
            'INSERT INTO insights_company_settings (cmp_id, settings, updated_by)
             VALUES (:cmp, :settings, :by)
             ON CONFLICT (cmp_id) DO UPDATE SET settings = EXCLUDED.settings, updated_by = EXCLUDED.updated_by, updated_at = NOW()',
            ['cmp' => $ctx->cmpId, 'settings' => Db::json($settings), 'by' => $auth->uuid],
        );

        Http::data(['settings' => $settings]);
    }

    /** @return array<string, mixed> */
    private static function preferences(Context $ctx, Auth $auth): array
    {
        $row = Db::first(
            'SELECT preferences FROM insights_user_preferences WHERE cmp_id = :cmp AND user_uuid = :uuid',
            ['cmp' => $ctx->cmpId, 'uuid' => $auth->uuid],
        );

        $stored = $row === null ? [] : Db::jsonColumn($row['preferences']);

        return $stored + [
            'timezone'          => 'Asia/Kolkata',
            'number_style'      => 'indian',
            'default_preset'    => 'this_month',
            'default_grain'     => 'month',
            'default_compare'   => 'previous_period',
            'density'           => 'comfortable',
            'default_dashboard' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function aiStatus(Context $ctx, Auth $auth): array
    {
        $status = ConsoleCredentials::status();

        // The admin hint names an environment variable. It goes only to
        // somebody who could change it; everybody else gets the plain reason.
        if (!Permissions::allows($ctx, $auth, 'settings.manage')) {
            $status['admin_hint'] = null;
        }

        $budget = Budget::check($ctx, $auth);
        $status['usage'] = ['used' => $budget['used'], 'limits' => $budget['limits']];
        $status['within_budget'] = $budget['allowed'];
        $status['policy'] = [
            'Questions and the figures behind them are sent to the model configured in Console. Customer and supplier names may be included where a question is about them; contact details, documents and transaction records are not.',
            'The model never calculates a total. Every figure it writes about was computed by Insights first.',
            'The model can propose a dashboard. It cannot post a voucher, change a master, place an order or send anything.',
        ];

        return $status;
    }

    private static function timezone(mixed $raw): string
    {
        $value = is_string($raw) ? trim($raw) : '';
        if ($value === '') {
            return 'Asia/Kolkata';
        }
        try {
            new \DateTimeZone($value);

            return $value;
        } catch (\Throwable) {
            return 'Asia/Kolkata';
        }
    }

    /** @return array<string, mixed> */
    private static function defaultCompanySettings(): array
    {
        return [
            'default_template'  => null,
            'allow_org_sharing' => true,
            'allow_ai'          => true,
            'currency'          => 'INR',
        ];
    }
}
