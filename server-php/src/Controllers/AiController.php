<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Ai\ConsoleCredentials;
use Aicountly\Api\Ai\Copilot;
use Aicountly\Api\Dashboards\DashboardService;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Ids;
use Aicountly\Api\Support\Period;

/**
 * Ask Insights.
 *
 * TWO STEPS, ALWAYS. `ask` produces an answer or a PROPOSAL; `apply` turns a
 * proposal into a dashboard. Nothing the model produced reaches the database
 * until somebody has seen it and pressed Apply, and the apply path re-validates
 * the whole proposal through the same code a hand-written request goes through.
 *
 * A proposal is stored so that Apply refers to something the server already
 * validated, rather than to a body the client sends back — which would make
 * "the AI proposed it" a way to post any configuration at all.
 */
final class AiController extends Controller
{
    /** How long a proposal is worth applying. */
    private const PROPOSAL_TTL_MINUTES = 60;

    public static function status(): void
    {
        [$auth, $ctx] = self::enter();

        $status = ConsoleCredentials::status();
        if (!Permissions::allows($ctx, $auth, 'settings.manage')) {
            $status['admin_hint'] = null;
        }

        Http::data($status + ['can_use' => Permissions::allows($ctx, $auth, 'ai.use')]);
    }

    public static function ask(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'ai.use');

        $question = (string) (Http::param('question') ?? '');
        $period = Period::fromRequest();

        $copilot = new Copilot($ctx, $auth, self::query($ctx, $auth));
        $answer = $copilot->ask($question, $period, [
            'dashboard_id' => Http::param('dashboard_id'),
        ]);

        // A proposal is persisted so Apply has something server-side to refer
        // to. An answer is not: it is a reading of figures that are already on
        // the screen, and keeping it would be keeping a copy of the numbers.
        if (($answer['kind'] ?? '') === 'proposal') {
            $answer['proposal_id'] = self::storeProposal($ctx, $auth, $answer);
        }

        Http::data($answer);
    }

    /**
     * Turn a stored proposal into a dashboard.
     *
     * The proposal is read from the database — not from the request — so the
     * only thing that can be applied is something this server produced and
     * validated. Applying it goes through DashboardService, which validates
     * again.
     */
    public static function apply(string $proposalId): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'ai.use');
        Permissions::assert($ctx, $auth, 'dashboard.create');

        $row = Db::first(
            'SELECT * FROM insights_ai_proposals
              WHERE cmp_id = :cmp AND public_id = :pid AND user_uuid = :uuid AND status = \'pending\'',
            ['cmp' => $ctx->cmpId, 'pid' => $proposalId, 'uuid' => $auth->uuid],
        );

        if ($row === null) {
            Http::notFound('That proposal is not available. Ask again — a proposal is only valid for an hour, and only for the person who asked.');
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            Db::update('insights_ai_proposals', ['status' => 'expired'], ['proposal_id' => (int) $row['proposal_id']]);
            Http::error(410, 'proposal_expired', 'That proposal has expired. Ask the question again to get a fresh one.');
        }

        $proposal = Db::jsonColumn($row['proposal']);
        $service = new DashboardService($ctx, $auth);

        $title = (string) (Http::param('title') ?? $proposal['title'] ?? 'New dashboard');
        $dashboard = $service->create([
            'title'       => $title,
            'description' => (string) ($proposal['description'] ?? ''),
            'settings'    => $proposal['settings'] ?? [],
            'widgets'     => $proposal['widgets'] ?? [],
            'visibility'  => 'private',
        ]);

        Db::update('insights_ai_proposals', [
            'status'       => 'applied',
            'applied_at'   => gmdate('c'),
        ], ['proposal_id' => (int) $row['proposal_id']]);

        Http::data($dashboard, 201);
    }

    public static function discard(string $proposalId): void
    {
        [$auth, $ctx] = self::enter();

        Db::run(
            'UPDATE insights_ai_proposals SET status = \'discarded\'
              WHERE cmp_id = :cmp AND public_id = :pid AND user_uuid = :uuid AND status = \'pending\'',
            ['cmp' => $ctx->cmpId, 'pid' => $proposalId, 'uuid' => $auth->uuid],
        );

        Http::data(['discarded' => true]);
    }

    /**
     * @param array<string, mixed> $answer
     */
    private static function storeProposal(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth, array $answer): ?string
    {
        if (($answer['widgets'] ?? []) === []) {
            return null;
        }

        $publicId = Ids::public('prp');

        try {
            Db::insert('insights_ai_proposals', [
                'public_id'    => $publicId,
                'cmp_id'       => $ctx->cmpId,
                'user_uuid'    => $auth->uuid,
                'kind'         => ($answer['intent'] ?? '') === 'edit_dashboard' ? 'edit_dashboard' : 'create_dashboard',
                'dashboard_id' => null,
                'prompt'       => mb_substr((string) ($answer['question'] ?? ''), 0, 500),
                'proposal'     => Db::json([
                    'title'       => $answer['title'] ?? 'New dashboard',
                    'description' => $answer['description'] ?? '',
                    'settings'    => $answer['settings'] ?? [],
                    'widgets'     => $answer['widgets'],
                ]),
                'expires_at'   => gmdate('c', time() + (self::PROPOSAL_TTL_MINUTES * 60)),
            ], 'proposal_id');
        } catch (\Throwable $e) {
            error_log('[insights-ai] could not store proposal: ' . $e->getMessage());

            return null;
        }

        return $publicId;
    }
}
