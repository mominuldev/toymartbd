<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureActiveStaffSession;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\RoleMatrixCoverage;

/*
 * Every admin route has a 403 role-matrix test (CLAUDE.md rule 21, P9 security review).
 *
 * How it stays honest — see tests/Support/RoleMatrixCoverage.php:
 *   - this file fails on any admin route that is neither in the registry
 *     (tests/Support/role-matrix-routes.php) nor exempt, and on any stale registry name;
 *   - CI records which routes RoleMatrix assertions actually reached and runs
 *     `php scripts/role-matrix-coverage.php verify`, so a name typed into the registry
 *     without a test fails there.
 *
 * Adding an admin route: write its RoleMatrix test (tests/Support/RoleMatrix.php), then add
 * the route name to tests/Support/role-matrix-routes.php.
 */

/** @return array<string, RoutingRoute> */
function adminRoutesByName(): array
{
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (str_starts_with($name, 'api.v1.admin.')) {
            $routes[$name] = $route;
        }
    }

    return $routes;
}

it('has a role-matrix test registered for every admin route', function (): void {
    $routes = adminRoutesByName();
    $missing = array_values(array_diff(
        array_keys($routes),
        RoleMatrixCoverage::registry(),
        array_keys(RoleMatrixCoverage::exempt()),
    ));

    $hint = 'These admin routes have no 403 role-matrix test (rule 21). Write one with '
        ."tests/Support/RoleMatrix.php, then add the name to tests/Support/role-matrix-routes.php:\n  "
        .implode("\n  ", array_map(static fn (string $n): string => implode('|', $routes[$n]->methods()).' /'.$routes[$n]->uri()." ({$n})", $missing));

    expect($missing)->toBe([], $hint);
});

it('lists no route in the registry that no longer exists', function (): void {
    $stale = array_values(array_diff(RoleMatrixCoverage::registry(), array_keys(adminRoutesByName())));

    expect($stale)->toBe([], 'Remove these from tests/Support/role-matrix-routes.php: '.implode(', ', $stale));
});

it('keeps the registry sorted and free of duplicates, so merges stay clean', function (): void {
    $registry = RoleMatrixCoverage::registry();
    $sorted = array_values(array_unique($registry));
    sort($sorted);

    expect($registry)->toBe($sorted);
});

it('puts every admin route but the public ones behind a staff session', function (): void {
    $public = ['api.v1.admin.ping', 'api.v1.admin.auth.login'];
    $open = [];

    foreach (adminRoutesByName() as $name => $route) {
        if (in_array($name, $public, true)) {
            continue;
        }
        $middleware = $route->gatherMiddleware();
        if (! in_array('auth:sanctum', $middleware, true) || ! in_array(EnsureActiveStaffSession::class, $middleware, true)) {
            $open[] = $name;
        }
    }

    expect($open)->toBe([]);
});
