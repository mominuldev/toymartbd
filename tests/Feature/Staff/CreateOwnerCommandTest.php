<?php

declare(strict_types=1);

use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;

/*
 * `toymart:owner:create` — the first owner login on a server where DatabaseSeeder creates
 * none. Password only from a hidden prompt; an existing email is never promoted.
 */

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
});

it('creates an active owner with a hashed password and audits the role grant', function (): void {
    $this->artisan('toymart:owner:create', ['--email' => ' Owner@ToyMart.test ', '--name' => 'রহিম Owner'])
        ->expectsQuestion('Password (min 12 characters)', 'a-long-secret-pass')
        ->expectsQuestion('Confirm password', 'a-long-secret-pass')
        ->assertSuccessful();

    $user = User::query()->where('email', 'owner@toymart.test')->sole();

    expect($user->name)->toBe('রহিম Owner')
        ->and($user->is_active)->toBeTrue()
        ->and($user->hasRole(Role::SuperAdmin->value))->toBeTrue()
        ->and(Hash::check('a-long-secret-pass', $user->password))->toBeTrue();

    $audit = AuditSensitive::query()->latest('id')->sole();
    expect($audit->action)->toBe(AuditAction::RoleAssigned)
        ->and($audit->user_id)->toBeNull()
        ->and($audit->model_id)->toBe($user->id)
        ->and(json_encode($audit->after))->not->toContain('a-long-secret-pass');
});

it('asks for the email and name when they are not passed', function (): void {
    $this->artisan('toymart:owner:create')
        ->expectsQuestion('Email', 'owner@toymart.test')
        ->expectsQuestion('Name', 'Owner')
        ->expectsQuestion('Password (min 12 characters)', 'a-long-secret-pass')
        ->expectsQuestion('Confirm password', 'a-long-secret-pass')
        ->assertSuccessful();

    expect(User::query()->where('email', 'owner@toymart.test')->exists())->toBeTrue();
});

it('refuses a short or mismatched password and creates nothing', function (string $password, string $confirmation): void {
    $this->artisan('toymart:owner:create', ['--email' => 'owner@toymart.test', '--name' => 'Owner'])
        ->expectsQuestion('Password (min 12 characters)', $password)
        ->expectsQuestion('Confirm password', $confirmation)
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'too short' => ['short', 'short'],
    'mismatch' => ['a-long-secret-pass', 'a-long-secret-pasX'],
]);

it('never promotes an existing account', function (): void {
    $viewer = User::factory()->withRole(Role::Viewer)->create(['email' => 'staff@toymart.test']);

    $this->artisan('toymart:owner:create', ['--email' => 'staff@toymart.test', '--name' => 'Owner'])
        ->expectsQuestion('Password (min 12 characters)', 'a-long-secret-pass')
        ->expectsQuestion('Confirm password', 'a-long-secret-pass')
        ->expectsOutputToContain('already exists')
        ->assertFailed();

    expect($viewer->refresh()->hasRole(Role::SuperAdmin->value))->toBeFalse();
});

it('refuses to run without a terminal to prompt in', function (): void {
    $this->artisan('toymart:owner:create', ['--email' => 'owner@toymart.test', '--name' => 'Owner', '--no-interaction' => true])
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});
