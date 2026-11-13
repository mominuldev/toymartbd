<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\RoleMatrixCoverage;

/*
 * Every admin route, swept (P9 security review, CLAUDE.md rule 21).
 *
 * The per-route role-matrix tests prove each endpoint's exact permission. This sweep is the
 * backstop that needs no upkeep: it walks the live route table, so a route added tomorrow is
 * covered tomorrow.
 *
 *   - a guest gets 401 on every admin route but the public ones;
 *   - a signed-in staff account with NO role and NO permission never gets a 2xx anywhere but
 *     its own-session routes. Missing bindings resolve to 404 before a policy runs, which is
 *     still "no data"; a 2xx would mean a route with no authorisation at all.
 */

/** @return list<array{string, string}> method + concrete URI per admin route */
function adminRouteSamples(): array
{
    $samples = [];

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (! str_starts_with($name, 'api.v1.admin.')) {
            continue;
        }

        $method = collect($route->methods())->first(fn (string $m): bool => $m !== 'HEAD') ?? 'GET';
        $uri = preg_replace_callback('/\{(\w+)\??\}/', static function (array $m) use ($route): string {
            $where = $route->wheres[$m[1]] ?? null;

            return match (true) {
                $where !== null && preg_match('/^'.$where.'$/', '999999') !== 1 => 'product',
                default => '999999',
            };
        }, $route->uri());

        $samples[$name] = [$method, '/'.$uri];
    }

    ksort($samples);

    return array_values(array_map(static fn (array $s, string $name): array => [$name, ...$s], $samples, array_keys($samples)));
}

it('refuses a guest with 401 on every admin route but the public ones', function (): void {
    $public = ['api.v1.admin.ping', 'api.v1.admin.auth.login'];
    $leaks = [];

    foreach (adminRouteSamples() as [$name, $method, $uri]) {
        if (in_array($name, $public, true)) {
            continue;
        }

        $status = $this->json($method, $uri)->getStatusCode();
        if ($status !== 401) {
            $leaks[] = "{$method} {$uri} ({$name}) → {$status}";
        }
    }

    expect($leaks)->toBe([]);
});

it('never answers a staff account without a role with a 2xx, except its own session', function (): void {
    // ~350 requests from one user would trip the admin group's 300/minute limit half-way.
    $this->withoutMiddleware(ThrottleRequests::class);
    $user = User::factory()->create(['is_active' => true])->refresh();
    $own = array_keys(RoleMatrixCoverage::exempt());
    $leaks = [];
    $statuses = [];

    foreach (adminRouteSamples() as [$name, $method, $uri]) {
        if (in_array($name, $own, true)) {
            continue;
        }

        auth()->forgetGuards();
        // An Idempotency-Key, so idempotent writes reach their policy instead of stopping at a 400.
        $status = $this->actingAs($user, 'web')->json($method, $uri, [], ['Idempotency-Key' => (string) Str::uuid()])->getStatusCode();
        $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        if ($status < 300 || $status === 422) {
            $leaks[] = "{$method} {$uri} ({$name}) → {$status}";
        }
    }

    expect($leaks)->toBe([])
        // The sweep really ran: unbound routes stop at their policy (403); bound ones mostly at the
        // missing binding (404) — the role-matrix registry is what proves those routes' policies.
        ->and(array_sum($statuses))->toBeGreaterThan(100)
        ->and(array_keys($statuses))->each->toBeIn([403, 404, 405])
        ->and($statuses[403] ?? 0)->toBeGreaterThan(array_sum($statuses) / 3);
});
