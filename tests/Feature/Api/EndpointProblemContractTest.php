<?php

declare(strict_types=1);

use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * Phase 9 coverage sweep (docs/audits/SEO-AND-COVERAGE.md, Task B): the RFC 9457 contract
 * per ENDPOINT, not just per exception class (ProblemDetailsTest covers the mapping itself).
 *
 *  - CLAUDE.md rule 16: every GET endpoint that documents a `filter[…]` or `sort` query
 *    parameter answers an unlisted one with a 400 `bad-request` problem. Driven by the same
 *    Scramble #[QueryParameter] attributes that publish the OpenAPI document, so a new list
 *    endpoint is covered the day it is documented.
 *  - Rule 18: every route with a path parameter answers a missing record with a 404
 *    `not-found` problem whose detail never names the model.
 *
 * Requests run as a super admin (admin) or with a storefront:read token (storefront), so
 * authorisation never masks the status under test. Agent 2's role matrix owns the 403s.
 */

const ENDPOINT_CONTRACT_PROBLEM_BASE = 'https://toymartbd.com/problems/';

/** Routes whose required query parameters must be present for the unlisted one to be what fails. */
const ENDPOINT_CONTRACT_BASELINE_QUERY = ['api/v1/storefront/search' => 'q=lego'];

/** Path-parameter routes that deliberately do not 404 on an unknown id. */
const ENDPOINT_CONTRACT_NOT_404 = [
    // Fire-and-forget hit counter from the storefront proxy: an unknown id is a no-op 204.
    'POST api/v1/storefront/redirects/{id}/hit',
    // The cart line PATCH validates its body before resolving the (cookie-scoped) cart.
    'PATCH api/v1/storefront/cart/items/{item}',
];

function endpointContractActingAs(mixed $test, string $uri): void
{
    // Created once per test (RefreshDatabase rolls them back between tests, hence the existence checks).
    static $admin = null;
    static $token = null;
    auth()->forgetGuards();
    $test->flushHeaders();

    if (str_starts_with($uri, 'api/v1/admin')) {
        if ($admin === null || User::query()->whereKey($admin->getKey())->doesntExist()) {
            (new RolesAndPermissionsSeeder)->run();
            $admin = User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
        }
        $test->actingAs($admin, 'web');

        return;
    }

    if ($token === null || PersonalAccessToken::findToken($token) === null) {
        $token = User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
    }
    $test->withToken($token);
}

function endpointContractAssertBadRequest(TestResponse $response, string $label): void
{
    expect($response->getStatusCode())->toBe(400, "{$label} must be a 400 (got {$response->getStatusCode()})");
    $response->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', ENDPOINT_CONTRACT_PROBLEM_BASE.'bad-request')
        ->assertJsonPath('status', 400);
}

/**
 * @return array<string, array{string, bool, bool}> uri => [uri, documents a filter, documents sort]
 */
function endpointContractListEndpoints(): array
{
    $rows = [];
    foreach (Route::getRoutes() as $route) {
        /** @var RouteDefinition $route */
        $uri = $route->uri();
        if (! in_array('GET', $route->methods(), true) || ! str_starts_with($uri, 'api/v1/') || str_contains($uri, '{')) {
            continue;
        }
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            continue;
        }
        [$class, $method] = explode('@', $action);
        $names = array_map(
            static fn (ReflectionAttribute $a): string => $a->newInstance()->name,
            (new ReflectionMethod($class, $method))->getAttributes(QueryParameter::class),
        );
        $filter = array_filter($names, static fn (string $n): bool => str_starts_with($n, 'filter[')) !== [];
        $sort = in_array('sort', $names, true);
        if ($filter || $sort) {
            $rows[$uri] = [$uri, $filter, $sort];
        }
    }
    ksort($rows);

    return $rows;
}

it('rejects an unlisted filter and an unlisted sort on every documented list endpoint (rule 16)', function (): void {
    $endpoints = endpointContractListEndpoints();

    // Guard against the sweep going vacuous (e.g. the attribute class is renamed).
    expect(count($endpoints))->toBeGreaterThanOrEqual(50);

    foreach ($endpoints as [$uri, $filter, $sort]) {
        endpointContractActingAs($this, $uri);
        $base = '/'.$uri.'?'.(ENDPOINT_CONTRACT_BASELINE_QUERY[$uri] ?? '');

        $this->getJson($base)->assertSuccessful();

        if ($filter) {
            endpointContractAssertBadRequest($this->getJson($base.'&filter[zz_unlisted]=1'), "GET {$uri} ?filter[zz_unlisted]");
        }
        if ($sort) {
            endpointContractAssertBadRequest($this->getJson($base.'&sort=zz_unlisted'), "GET {$uri} ?sort=zz_unlisted");
        }
    }
});

it('answers a missing record on every parameterised endpoint with a not-found problem that names no model (rule 18)', function (): void {
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        /** @var RouteDefinition $route */
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/admin/') && ! str_starts_with($uri, 'api/v1/storefront/')) {
            continue;
        }
        if (! str_contains($uri, '{') || str_starts_with($uri, 'api/v1/storefront/account')) {
            continue; // account routes need a customer token: their 404s live in tests/Feature/Storefront/Account
        }

        foreach (array_intersect(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) as $method) {
            if (in_array("{$method} {$uri}", ENDPOINT_CONTRACT_NOT_404, true)) {
                continue;
            }
            // A numeric-constrained parameter gets an id that cannot exist; anything else a slug that cannot.
            $path = preg_replace_callback('/\{(\w+)\??\}/', static fn (array $m): string => ($route->wheres[$m[1]] ?? null) === '[0-9]+' ? '987654321' : 'zz-no-such-record', $uri);

            endpointContractActingAs($this, $uri);
            $response = $this->json($method, '/'.$path, [], ['Idempotency-Key' => 'contract-'.bin2hex(random_bytes(8))]);

            if ($response->getStatusCode() === 401) {
                continue; // cart/checkout routes scoped to a cart cookie or customer session
            }

            expect($response->getStatusCode())->toBe(404, "{$method} /{$path} must be a 404 (got {$response->getStatusCode()})");
            $response->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', ENDPOINT_CONTRACT_PROBLEM_BASE.'not-found')
                ->assertJsonPath('detail', 'The requested resource was not found.');
            expect((string) $response->getContent())->not->toContain('App\\');
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(150);
});
