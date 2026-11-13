<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Routing\Route;
use Illuminate\Testing\TestResponse;

/**
 * Which admin routes have a 403 role-matrix test (CLAUDE.md rule 21, P9 security review).
 *
 * Two halves, so the check is both fast and honest:
 *
 * 1. The REGISTRY — tests/Support/role-matrix-routes.php — lists every admin route
 *    name that has a matrix test. tests/Arch/AdminRouteAuthorisationTest.php fails
 *    on any admin route missing from it, and on any stale name. Runs on every
 *    `php artisan test`, even a filtered one.
 * 2. The RECORDING — when ROLE_MATRIX_COVERAGE_FILE is set, every request a
 *    RoleMatrix assertion sees appends its route name to that file. CI runs the
 *    suite with it set, then `php scripts/role-matrix-coverage.php verify <file>`
 *    fails if the registry claims a route no matrix test actually reached. So the
 *    registry cannot be satisfied by just typing a name into it.
 *
 * Adding an admin route: write its RoleMatrix test, then add the route name to the
 * registry (or regenerate it: see scripts/role-matrix-coverage.php).
 */
final class RoleMatrixCoverage
{
    /** @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response */
    public static function record(TestResponse $response): void
    {
        $file = getenv('ROLE_MATRIX_COVERAGE_FILE');

        if (! is_string($file) || $file === '') {
            return;
        }

        $route = $response->baseRequest?->route();

        if (! $route instanceof Route || $route->getName() === null) {
            return;
        }

        file_put_contents($file, $route->getName()."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Admin routes that need no matrix test because they are not staff-permissioned:
     * reachable before sign-in, or by any signed-in staff member for their own account.
     * Each still has a dedicated test of its own (named here).
     *
     * @return array<string, string> route name => why, and where it is tested
     */
    public static function exempt(): array
    {
        return [
            'api.v1.admin.ping' => 'public liveness probe, no data (tests/Feature/Api)',
            'api.v1.admin.auth.login' => 'sign-in itself; throttled (tests/Feature/Auth)',
            'api.v1.admin.auth.logout' => 'own session only (tests/Feature/Auth)',
            'api.v1.admin.auth.me' => 'own profile only (tests/Feature/Auth)',
        ];
    }

    /** @return list<string> */
    public static function registry(): array
    {
        /** @var list<string> $names */
        $names = require __DIR__.'/role-matrix-routes.php';

        return $names;
    }
}
