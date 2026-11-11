<?php

declare(strict_types=1);

use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;

/*
 * Admin SPA cookie session (P3-API-05). Requests carry a Referer from a
 * SANCTUM_STATEFUL_DOMAINS origin, exactly as the same-origin SPA does, so
 * Sanctum gives them the session stack.
 */

const ADMIN_LOGIN_URI = '/api/v1/admin/auth/login';
const ADMIN_LOGOUT_URI = '/api/v1/admin/auth/logout';
const ADMIN_ME_URI = '/api/v1/admin/auth/me';

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withHeader('Referer', config('app.url').'/admin');
});

function adminStaff(Role $role = Role::OrderOperator, array $attributes = []): User
{
    return User::factory()->withRole($role)->create([
        'email' => 'rahim@toymartbd.test',
        'password' => 'correct-horse-battery',
        ...$attributes,
    ])->refresh(); // load DB defaults (is_active) as a real request would
}

function assertAuthProblem(TestResponse $response, int $status, string $type): TestResponse
{
    return $response->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', "https://toymartbd.com/problems/{$type}")
        ->assertJsonPath('status', $status);
}

it('signs in, returns the /me body and stamps last_login_at', function (): void {
    $user = adminStaff();

    $response = $this->postJson(ADMIN_LOGIN_URI, ['email' => 'rahim@toymartbd.test', 'password' => 'correct-horse-battery']);

    $response->assertOk()
        ->assertExactJsonStructure(['id', 'name', 'email', 'locale', 'roles', 'permissions'])
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('email', 'rahim@toymartbd.test')
        ->assertJsonPath('locale', null)
        ->assertJsonPath('roles', ['order_operator']);

    expect($response->json('permissions'))->toEqualCanonicalizing(Role::OrderOperator->permissionNames())
        ->and($user->fresh()?->last_login_at)->not->toBeNull()
        ->and(auth('web')->id())->toBe($user->id);

    // The same session now answers /me.
    $this->getJson(ADMIN_ME_URI)->assertOk()->assertJsonPath('id', $user->id);
});

it('regenerates the session id on sign-in', function (): void {
    adminStaff();
    $this->getJson(ADMIN_ME_URI); // start a session
    $before = session()->getId();

    $this->postJson(ADMIN_LOGIN_URI, ['email' => 'rahim@toymartbd.test', 'password' => 'correct-horse-battery'])->assertOk();

    expect(session()->getId())->not->toBe($before);
});

it('rejects a wrong password, an unknown email and an inactive account identically', function (array $credentials, array $attributes): void {
    adminStaff(attributes: $attributes);

    $response = assertAuthProblem($this->postJson(ADMIN_LOGIN_URI, $credentials), 422, 'validation-error');

    expect($response->json('errors'))->toBe([['field' => 'email', 'message' => __('auth.failed')]])
        ->and(auth('web')->check())->toBeFalse();
})->with([
    'wrong password' => [['email' => 'rahim@toymartbd.test', 'password' => 'nope-nope-nope'], []],
    'unknown email' => [['email' => 'nobody@toymartbd.test', 'password' => 'correct-horse-battery'], []],
    'inactive account' => [['email' => 'rahim@toymartbd.test', 'password' => 'correct-horse-battery'], ['is_active' => false]],
]);

it('validates the login body through the FormRequest', function (): void {
    $response = assertAuthProblem($this->postJson(ADMIN_LOGIN_URI, ['email' => 'not-an-email', 'remember' => 'maybe']), 422, 'validation-error');

    expect(collect($response->json('errors'))->pluck('field')->unique()->sort()->values()->all())
        ->toBe(['email', 'password', 'remember']);
});

it('throttles sign-in at 5 attempts a minute', function (): void {
    adminStaff();

    foreach (range(1, 5) as $attempt) {
        $this->postJson(ADMIN_LOGIN_URI, ['email' => 'rahim@toymartbd.test', 'password' => 'wrong-'.$attempt])->assertStatus(422);
    }

    assertAuthProblem($this->postJson(ADMIN_LOGIN_URI, ['email' => 'rahim@toymartbd.test', 'password' => 'correct-horse-battery']), 429, 'too-many-requests')
        ->assertHeader('Retry-After');
});

it('refuses to sign in without a first-party session', function (): void {
    adminStaff();

    assertAuthProblem(
        $this->withHeaders(['Referer' => 'https://evil.example/'])
            ->postJson(ADMIN_LOGIN_URI, ['email' => 'rahim@toymartbd.test', 'password' => 'correct-horse-battery']),
        400,
        'bad-request',
    );
});

it('signs out with 204 and invalidates the session', function (): void {
    adminStaff();
    $this->postJson(ADMIN_LOGIN_URI, ['email' => 'rahim@toymartbd.test', 'password' => 'correct-horse-battery'])->assertOk();

    $this->postJson(ADMIN_LOGOUT_URI)->assertNoContent();

    auth()->forgetGuards();
    assertAuthProblem($this->getJson(ADMIN_ME_URI), 401, 'unauthenticated');
});

it('answers /me with 401 for a guest', function (): void {
    assertAuthProblem($this->getJson(ADMIN_ME_URI), 401, 'unauthenticated');
});

it('lists every permission for super_admin on /me', function (): void {
    $this->actingAs(adminStaff(Role::SuperAdmin), 'web');

    $this->getJson(ADMIN_ME_URI)->assertOk()
        ->assertJsonPath('roles', ['super_admin'])
        ->assertJsonPath('permissions', Permission::values());
});

it('signs out a staff member deactivated mid-session with 401', function (): void {
    $user = adminStaff();
    $this->postJson(ADMIN_LOGIN_URI, ['email' => 'rahim@toymartbd.test', 'password' => 'correct-horse-battery'])->assertOk();

    $user->forceFill(['is_active' => false])->save();
    auth()->forgetGuards();

    assertAuthProblem($this->getJson(ADMIN_ME_URI), 401, 'unauthenticated');

    expect(auth('web')->check())->toBeFalse();
});

it('keeps the admin routes behind auth:sanctum and the active-staff check', function (string $name): void {
    $middleware = Illuminate\Support\Facades\Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

    expect($middleware)->toContain('auth:sanctum')
        ->toContain(App\Http\Middleware\EnsureActiveStaffSession::class);
})->with(['api.v1.admin.auth.logout', 'api.v1.admin.auth.me', 'api.v1.admin.staff.index']);
