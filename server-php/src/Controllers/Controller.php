<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Metrics\QueryService;

/**
 * The three lines every scoped endpoint starts with, in one place.
 *
 * ORDER MATTERS AND IS NOT NEGOTIABLE:
 *
 *   1. Who is calling            Auth::require()
 *   2. Which company, and may they open it   Context::assertAllowed()
 *   3. What may they do here     Permissions::assert(), in the controller
 *
 * The tenant check runs BEFORE any permission check, because a permission is
 * company-scoped: asking "may you view dashboards" before establishing which
 * company you are in is asking the wrong question. It also runs before any
 * query, so a company somebody has no access to never reaches a WHERE clause.
 */
abstract class Controller
{
    /** @return array{0: Auth, 1: Context} */
    protected static function enter(): array
    {
        $auth = Auth::require();
        $ctx = Context::fromRequest();
        $ctx->assertAllowed($auth);

        return [$auth, $ctx];
    }

    /** The query service for this caller, holding their own session key. */
    protected static function query(Context $ctx, Auth $auth): QueryService
    {
        return new QueryService($auth, $ctx);
    }
}
