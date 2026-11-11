<?php

declare(strict_types=1);

use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\EnforcedCsrf;

/*
 * CSRF on admin writes (P3-API-05). Laravel skips CSRF checks under unit tests,
 * so these swap in EnforcedCsrf to prove the real middleware stack enforces it.
 */

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['sanctum.middleware.validate_csrf_token' => EnforcedCsrf::class]);
    $this->withHeader('Referer', config('app.url').'/admin');

    User::factory()->withRole(Role::Manager)->create([
        'email' => 'karim@toymartbd.test',
        'password' => 'correct-horse-battery',
    ]);
});

it('rejects a cookie-session login POST without X-XSRF-TOKEN with a 419 problem', function (): void {
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'karim@toymartbd.test', 'password' => 'correct-horse-battery'])
        ->assertStatus(419)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://toymartbd.com/problems/csrf-token-mismatch');

    expect(auth('web')->check())->toBeFalse();
});

it('rejects a signed-in logout POST without X-XSRF-TOKEN with a 419 problem', function (): void {
    $this->actingAs(User::query()->where('email', 'karim@toymartbd.test')->firstOrFail(), 'web');

    $this->postJson('/api/v1/admin/auth/logout')
        ->assertStatus(419)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/csrf-token-mismatch');
});

it('accepts the SPA flow: GET /sanctum/csrf-cookie, then POST with X-XSRF-TOKEN', function (): void {
    $cookie = $this->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN')
        ->getCookie('XSRF-TOKEN', decrypt: false);

    expect($cookie)->not->toBeNull();

    // The browser echoes the (encrypted) cookie value back in the header, as axios does.
    $this->withHeader('X-XSRF-TOKEN', (string) $cookie?->getValue())
        ->postJson('/api/v1/admin/auth/login', ['email' => 'karim@toymartbd.test', 'password' => 'correct-horse-battery'])
        ->assertOk()
        ->assertJsonPath('roles', ['manager']);

    expect(auth('web')->check())->toBeTrue();
});

it('rejects a forged X-XSRF-TOKEN', function (): void {
    $this->get('/sanctum/csrf-cookie')->assertNoContent();

    $this->withHeader('X-XSRF-TOKEN', 'forged')
        ->postJson('/api/v1/admin/auth/login', ['email' => 'karim@toymartbd.test', 'password' => 'correct-horse-battery'])
        ->assertStatus(419);
});

it('does not require a CSRF token on reads', function (): void {
    $this->getJson('/api/v1/admin/auth/me')->assertStatus(401);
});
