<?php

declare(strict_types=1);

use App\Console\Commands\IssueStorefrontToken;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * The storefront server token (P3-API-05): limited abilities, stored hashed,
 * and unable to reach any admin route.
 */

function issueStorefrontToken(array $options = []): string
{
    expect(Artisan::call('toymart:storefront-token', $options))->toBe(0);

    $lines = array_values(array_filter(array_map('trim', explode("\n", Artisan::output()))));

    return (string) end($lines);
}

it('issues a storefront:read token, printed once and stored only as a hash', function (): void {
    $plain = issueStorefrontToken();

    expect($plain)->toMatch('/^\d+\|\S{40,}$/');

    $token = PersonalAccessToken::findToken($plain);

    expect($token)->not->toBeNull()
        ->and($token?->abilities)->toBe(IssueStorefrontToken::ABILITIES)
        ->and($token?->can('storefront:read'))->toBeTrue()
        ->and($token?->can('orders.view'))->toBeFalse()
        ->and($token?->token)->toBe(hash('sha256', explode('|', $plain, 2)[1]))
        ->and($token?->token)->not->toContain(explode('|', $plain, 2)[1]);
});

it('owns the token by an inactive, roleless service account', function (): void {
    issueStorefrontToken();

    $account = User::query()->where('email', IssueStorefrontToken::SERVICE_EMAIL)->firstOrFail();

    expect($account->is_active)->toBeFalse()
        ->and($account->getRoleNames()->all())->toBe([]);
});

it('revokes earlier tokens with --rotate', function (): void {
    $old = issueStorefrontToken();
    $new = issueStorefrontToken(['--rotate' => true]);

    expect(PersonalAccessToken::findToken($old))->toBeNull()
        ->and(PersonalAccessToken::findToken($new))->not->toBeNull();
});

it('authenticates a bearer route that requires storefront:read', function (): void {
    Route::middleware(['api', 'auth:sanctum', CheckAbilities::class.':storefront:read'])
        ->get('/api/v1/storefront/_token-probe', fn () => ['ok' => true]);

    $this->withToken(issueStorefrontToken())
        ->getJson('/api/v1/storefront/_token-probe')
        ->assertOk();
});

it('cannot reach an admin route with the storefront token', function (string $method, string $uri): void {
    $response = $this->withToken(issueStorefrontToken())->json($method, $uri);

    expect($response->getStatusCode())->toBeIn([401, 403]);
    $response->assertHeader('Content-Type', 'application/problem+json');
})->with([
    ['GET', '/api/v1/admin/auth/me'],
    ['GET', '/api/v1/admin/staff'],
    ['POST', '/api/v1/admin/auth/logout'],
]);

it('refuses a bearer token on the admin API even when its owner is active staff', function (): void {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
    $manager = User::factory()->withRole(App\Models\Enums\Role::SuperAdmin)->create();
    $token = $manager->createToken('stolen', ['*'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/admin/staff')
        ->assertForbidden()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/forbidden');
});
