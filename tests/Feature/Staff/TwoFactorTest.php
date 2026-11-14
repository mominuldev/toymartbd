<?php

declare(strict_types=1);

use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\RoleMatrix;

/*
 * Staff two-factor sign-in (owner decision 2026-11-14): required for anyone who can see
 * money, authenticator-app codes with single-use recovery codes, an owner can reset a lost
 * one. Requests carry a same-origin Referer so Sanctum gives them the session stack.
 */

const TF_URL = '/api/v1/admin/auth/two-factor';
const TF_LOGIN = '/api/v1/admin/auth/login';
const TF_CHALLENGE = '/api/v1/admin/auth/two-factor-challenge';
const TF_PASSWORD = 'correct-horse-battery';

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withHeader('Referer', config('app.url').'/admin');
});

function tfStaff(Role $role = Role::Accountant, string $email = 'kamrul@toymartbd.test'): User
{
    return User::factory()->withRole($role)->create(['email' => $email, 'password' => TF_PASSWORD])->refresh();
}

/** The code an authenticator app shows `$steps` × 30 s from now. */
function totp(string $secret, int $steps = 0): string
{
    $g = new Google2FA;

    return $g->oathTotp($secret, $g->getTimestamp() + $steps);
}

/**
 * Acts as `$user` from a clean session. Sanctum's AuthenticateSession pins a session to the
 * password hash it signed in with, so a second actor in the same session would be signed out.
 */
function actAs(User $user): void
{
    auth()->forgetGuards();
    test()->flushSession();
    test()->actingAs($user->fresh() ?? $user, 'web');
}

/** Enrols `$user` through the API (signed in as them) and returns [secret, recovery codes]. */
function enrol(User $user): array
{
    actAs($user);
    $secret = test()->postJson(TF_URL)->assertOk()->json('secret');
    $codes = test()->postJson(TF_URL.'/confirm', ['code' => totp($secret)])->assertOk()->json('recovery_codes');
    auth()->forgetGuards();
    test()->flushSession();

    return [$secret, $codes];
}

it('sets up two-factor: QR and key once, confirmed by a code, 8 recovery codes, secret encrypted at rest', function (): void {
    $user = tfStaff();
    $this->actingAs($user, 'web');

    $setup = $this->postJson(TF_URL)->assertOk();
    $secret = $setup->json('secret');
    expect($secret)->toMatch('/^[A-Z2-7]{32}$/')
        ->and($setup->json('otpauth_uri'))->toStartWith('otpauth://totp/')->toContain('kamrul%40toymartbd.test')
        ->and($setup->json('qr_svg'))->toContain('<svg');

    $this->getJson(TF_URL)->assertOk()->assertExactJson(['enabled' => false, 'required' => false, 'confirmed_at' => null, 'recovery_codes_left' => 0]);

    $this->postJson(TF_URL.'/confirm', ['code' => '000000'])->assertStatus(422)->assertJsonPath('errors.0.field', 'code');

    $codes = $this->postJson(TF_URL.'/confirm', ['code' => totp($secret)])->assertOk()->json('recovery_codes');
    expect($codes)->toHaveCount(8)->each->toMatch('/^[a-z0-9]{5}-[a-z0-9]{5}$/');

    $status = $this->getJson(TF_URL)->assertOk();
    expect($status->json('enabled'))->toBeTrue()
        ->and($status->json('recovery_codes_left'))->toBe(8)
        ->and($status->json())->not->toHaveKey('secret');

    $raw = DB::table('users')->where('id', $user->id)->first();
    expect($raw->two_factor_secret)->not->toContain($secret)
        ->and($raw->two_factor_recovery_codes)->not->toContain($codes[0]);

    $audit = AuditSensitive::query()->sole();
    expect($audit->action)->toBe(AuditAction::CredentialChanged)
        ->and($audit->after)->toBe(['scope' => 'two_factor', 'two_factor' => 'enabled'])
        ->and(json_encode($audit->getAttributes()))->not->toContain($secret);

    $this->postJson(TF_URL)->assertStatus(409);
});

it('never puts the secret or recovery hashes in the staff list or /me', function (): void {
    [$secret] = enrol($accountant = tfStaff());
    $owner = User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
    enrol($owner);

    actAs($owner);
    $list = $this->getJson('/api/v1/admin/staff')->assertOk();
    $me = $this->getJson('/api/v1/admin/auth/me')->assertOk();

    expect($list->getContent())->not->toContain($secret)->not->toContain('two_factor_secret')
        ->and(collect($list->json('data'))->firstWhere('id', $accountant->id))->toMatchArray(['two_factor_enabled' => true, 'two_factor_required' => true])
        ->and($me->json('two_factor'))->toBe(['enabled' => true, 'required' => false]);
});

it('signs in in two steps when two-factor is on, and a code works once', function (): void {
    [$secret] = enrol(tfStaff());

    $this->postJson(TF_LOGIN, ['email' => 'kamrul@toymartbd.test', 'password' => TF_PASSWORD])
        ->assertStatus(202)
        ->assertExactJson(['two_factor' => true]);
    expect(auth('web')->check())->toBeFalse();
    $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();

    $this->postJson(TF_CHALLENGE, ['code' => '123456'])->assertStatus(422)->assertJsonPath('errors.0.field', 'code');

    $code = totp($secret, 1);
    $this->postJson(TF_CHALLENGE, ['code' => $code])->assertOk()->assertJsonPath('email', 'kamrul@toymartbd.test');
    $this->getJson('/api/v1/admin/auth/me')->assertOk();

    // The same code in a fresh sign-in is a replay.
    $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
    auth()->forgetGuards();
    $this->postJson(TF_LOGIN, ['email' => 'kamrul@toymartbd.test', 'password' => TF_PASSWORD])->assertStatus(202);
    $this->postJson(TF_CHALLENGE, ['code' => $code])->assertStatus(422);
});

it('accepts a recovery code once, and audits its use', function (): void {
    [, $codes] = enrol(tfStaff());

    $this->postJson(TF_LOGIN, ['email' => 'kamrul@toymartbd.test', 'password' => TF_PASSWORD])->assertStatus(202);
    $this->postJson(TF_CHALLENGE, ['recovery_code' => strtoupper($codes[0])])->assertOk();

    $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
    auth()->forgetGuards();
    $this->postJson(TF_LOGIN, ['email' => 'kamrul@toymartbd.test', 'password' => TF_PASSWORD])->assertStatus(202);
    $this->postJson(TF_CHALLENGE, ['recovery_code' => $codes[0]])->assertStatus(422)->assertJsonPath('errors.0.field', 'recovery_code');

    expect(AuditSensitive::query()->latest('id')->first()?->after)->toBe(['scope' => 'two_factor', 'two_factor' => 'recovery_code_used']);
});

it('expires a half-finished sign-in, and refuses a challenge with none pending', function (): void {
    [$secret] = enrol(tfStaff());

    $this->postJson(TF_CHALLENGE, ['code' => totp($secret)])->assertStatus(422)->assertJsonPath('errors.0.message', __('staff.two_factor_expired'));

    $this->postJson(TF_LOGIN, ['email' => 'kamrul@toymartbd.test', 'password' => TF_PASSWORD])->assertStatus(202);
    $this->travel(6)->minutes();
    $this->postJson(TF_CHALLENGE, ['code' => totp($secret)])->assertStatus(422)->assertJsonPath('errors.0.message', __('staff.two_factor_expired'));
});

it('refuses a deactivated account at the second step', function (): void {
    [$secret] = enrol($user = tfStaff());
    $this->postJson(TF_LOGIN, ['email' => 'kamrul@toymartbd.test', 'password' => TF_PASSWORD])->assertStatus(202);
    $user->forceFill(['is_active' => false])->save();

    $this->postJson(TF_CHALLENGE, ['code' => totp($secret)])->assertStatus(422);
    expect(auth('web')->check())->toBeFalse();
});

it('limits second-step guesses per session and per pending account', function (): void {
    // Asserted on the limiter itself: the test client gets a new session id on every request.
    $request = Illuminate\Http\Request::create(TF_CHALLENGE, 'POST');
    $session = app('session')->driver();
    $session->put(App\Http\Controllers\Api\V1\Admin\Auth\AuthController::TWO_FACTOR_PENDING, ['id' => 42]);
    $request->setLaravelSession($session);

    /** @var list<Illuminate\Cache\RateLimiting\Limit> $limits */
    $limits = Illuminate\Support\Facades\RateLimiter::limiter('two-factor')($request);

    expect($limits)->toHaveCount(2)
        ->and($limits[0]->key)->toBe('2fa-session:'.$session->getId())
        ->and($limits[0]->maxAttempts)->toBe(App\Providers\ApiServiceProvider::TWO_FACTOR_PER_MINUTE)
        ->and($limits[1]->key)->toBe('2fa-account:42')
        ->and($limits[1]->maxAttempts)->toBe(App\Providers\ApiServiceProvider::TWO_FACTOR_PER_ACCOUNT_PER_FIFTEEN_MINUTES)
        ->and($limits[1]->decaySeconds)->toBe(900);
});

it('throttles second-step guesses per account, however many sessions are used', function (): void {
    enrol(tfStaff());
    $this->postJson(TF_LOGIN, ['email' => 'kamrul@toymartbd.test', 'password' => TF_PASSWORD])->assertStatus(202);

    // A new session id before every guess (the pending sign-in kept), as if spread over browsers.
    // Enrolment's confirm step already used one hit from the same per-account bucket.
    for ($i = 1; $i < App\Providers\ApiServiceProvider::TWO_FACTOR_PER_ACCOUNT_PER_FIFTEEN_MINUTES; $i++) {
        session()->regenerate();
        $this->postJson(TF_CHALLENGE, ['code' => '000000'])->assertStatus(422);
    }
    session()->regenerate();
    $this->postJson(TF_CHALLENGE, ['code' => '000000'])->assertStatus(429);
});

it('wants one of code or recovery_code, not both', function (): void {
    $this->postJson(TF_CHALLENGE, [])->assertStatus(422);
    $this->postJson(TF_CHALLENGE, ['code' => '123456', 'recovery_code' => 'abcde-fghij'])->assertStatus(422);
});

describe('enforcement', function (): void {
    beforeEach(fn () => config(['auth.two_factor.enforce' => true]));

    it('holds anyone who can see money at setup until they enrol', function (): void {
        $user = tfStaff(Role::Accountant);
        $this->actingAs($user, 'web');

        $this->getJson('/api/v1/admin/finance/expenses')
            ->assertStatus(403)
            ->assertJsonPath('type', 'https://toymartbd.com/problems/two-factor-required');
        $this->getJson('/api/v1/admin/auth/me')->assertOk()->assertJsonPath('two_factor', ['enabled' => false, 'required' => true]);
        $this->getJson(TF_URL)->assertOk()->assertJsonPath('required', true);

        $secret = $this->postJson(TF_URL)->assertOk()->json('secret');
        $this->postJson(TF_URL.'/confirm', ['code' => totp($secret)])->assertOk();

        actAs($user); // a real request loads the user afresh
        $this->getJson('/api/v1/admin/finance/expenses')->assertOk();
    });

    it('requires it of owners, not of roles that cannot see money', function (): void {
        $this->actingAs(tfStaff(Role::SuperAdmin, 'owner@toymartbd.test'), 'web');
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(403);

        actAs(tfStaff(Role::OrderOperator, 'rina@toymartbd.test'));
        $this->getJson('/api/v1/admin/orders')->assertOk();
        $this->getJson('/api/v1/admin/auth/me')->assertJsonPath('two_factor.required', false);
    });

    it('follows role edits: granting a money permission requires it at the next request', function (): void {
        $operator = tfStaff(Role::OrderOperator, 'rina@toymartbd.test');
        Spatie\Permission\Models\Role::findByName('order_operator', 'web')->givePermissionTo(Permission::CatalogViewCost->value);
        app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        actAs($operator);
        $this->getJson('/api/v1/admin/orders')
            ->assertStatus(403)->assertJsonPath('type', 'https://toymartbd.com/problems/two-factor-required');
    });

    it('will not let a required person switch it off', function (): void {
        [$secret] = enrol($user = tfStaff(Role::Accountant));
        $this->actingAs($user->fresh(), 'web');

        $this->deleteJson(TF_URL, ['code' => totp($secret, 1)])->assertStatus(409);
        expect($user->fresh()?->hasTwoFactor())->toBeTrue();
    });
});

it('lets someone who is not required switch it off with a code, audited', function (): void {
    [$secret] = enrol($user = tfStaff(Role::Viewer));
    actAs($user);

    $this->deleteJson(TF_URL, ['code' => '000000'])->assertStatus(422);
    $this->deleteJson(TF_URL, ['code' => totp($secret, 1)])->assertNoContent();

    expect($user->fresh()?->hasTwoFactor())->toBeFalse()
        ->and($user->fresh()?->two_factor_secret)->toBeNull()
        ->and(AuditSensitive::query()->latest('id')->first()?->after)->toBe(['scope' => 'two_factor', 'two_factor' => 'disabled']);
});

it('regenerates recovery codes with a current code; the old ones stop working', function (): void {
    [$secret, $old] = enrol($user = tfStaff());
    actAs($user);

    $this->postJson(TF_URL.'/recovery-codes', ['code' => '000000'])->assertStatus(422);
    $new = $this->postJson(TF_URL.'/recovery-codes', ['code' => totp($secret, 1)])->assertOk()->json('recovery_codes');
    expect($new)->toHaveCount(8)->not->toContain($old[0]);

    auth()->forgetGuards();
    $this->flushSession();
    $this->postJson(TF_LOGIN, ['email' => 'kamrul@toymartbd.test', 'password' => TF_PASSWORD])->assertStatus(202);
    $this->postJson(TF_CHALLENGE, ['recovery_code' => $old[0]])->assertStatus(422);
    $this->postJson(TF_CHALLENGE, ['recovery_code' => $new[0]])->assertOk();
});

it('lets an owner reset someone else\'s lost two-factor, audited, but not their own', function (): void {
    enrol($staff = tfStaff());
    $staff->createToken('device');
    $owner = User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
    actAs($owner);

    $this->postJson('/api/v1/admin/staff/'.$staff->id.'/two-factor/reset')
        ->assertOk()
        ->assertJsonPath('two_factor_enabled', false);
    expect($staff->fresh()?->two_factor_secret)->toBeNull()
        ->and($staff->tokens()->count())->toBe(0);

    $audit = AuditSensitive::query()->latest('id')->first();
    expect($audit?->user_id)->toBe($owner->id)
        ->and($audit?->model_id)->toBe($staff->id)
        ->and($audit?->after)->toBe(['scope' => 'two_factor', 'two_factor' => 'reset_by_owner']);

    $this->postJson('/api/v1/admin/staff/'.$owner->id.'/two-factor/reset')->assertStatus(409);
});

it('guards POST /staff/{user}/two-factor/reset with staff.manage', function (Role $role, bool $allowed): void {
    (new RolesAndPermissionsSeeder)->run();
    $target = User::factory()->withRole(Role::Viewer)->create();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/staff/'.$target->id.'/two-factor/reset'));
})->with(RoleMatrix::dataset(Permission::StaffManage));
