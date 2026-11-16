<?php

declare(strict_types=1);

use App\Domain\Staff\Services\TwoFactor;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SecuritySettingsSeeder;
use Tests\Support\RoleMatrix;

/*
 * Staff & roles › Two-factor sign-in (owner request 2026-11-16): the owner chooses whether staff
 * who can see money must use two-factor. Off by default — optional, nobody is held at setup —
 * and anyone who set it up still gives a code at sign-in. The config kill switch is on here so
 * only the setting decides.
 */

const TF_SETTING_URL = '/api/v1/admin/staff/two-factor';

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withHeader('Referer', config('app.url').'/admin');
    config(['auth.two_factor.enforce' => true]);
});

function tfSettingStaff(Role $role, string $email): User
{
    return User::factory()->withRole($role)->create(['email' => $email])->refresh();
}

it('is off by default: someone who can see money uses the admin without enrolling', function (): void {
    $this->actingAs(tfSettingStaff(Role::Accountant, 'kamrul@toymartbd.test'), 'web');

    expect(TwoFactor::requiredByOwner())->toBeFalse();
    $this->getJson('/api/v1/admin/finance/expenses')->assertOk();
    $this->getJson('/api/v1/admin/auth/me')->assertJsonPath('two_factor.required', false);
});

it('lets the owner require it, which holds the unenrolled at setup', function (): void {
    $owner = tfSettingStaff(Role::SuperAdmin, 'owner@toymartbd.test');
    $owner->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
    $accountant = tfSettingStaff(Role::Accountant, 'kamrul@toymartbd.test');
    tfSettingStaff(Role::OrderOperator, 'rina@toymartbd.test'); // cannot see money: not counted

    $this->actingAs($owner, 'web');
    $this->getJson(TF_SETTING_URL)->assertOk()->assertExactJson(['required' => false, 'staff_without' => 1]);
    $this->putJson(TF_SETTING_URL, ['required' => true])->assertOk()->assertJsonPath('required', true);

    $audit = AuditSensitive::query()->latest('id')->first();
    expect($audit?->action)->toBe(AuditAction::CredentialChanged)
        ->and($audit?->user_id)->toBe($owner->id)
        ->and($audit?->before)->toBe(['scope' => 'two_factor_policy', 'required' => false])
        ->and($audit?->after)->toBe(['scope' => 'two_factor_policy', 'required' => true]);

    app('auth')->forgetGuards(); // sanctum keeps the previous request's user otherwise
    $this->actingAs($accountant, 'web');
    $this->getJson('/api/v1/admin/finance/expenses')
        ->assertStatus(403)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/two-factor-required');
});

it('switching it off again releases them; saving the same value writes no audit row', function (): void {
    Setting::put(TwoFactor::SETTING, true, SettingGroup::System);
    $this->actingAs(tfSettingStaff(Role::SuperAdmin, 'owner@toymartbd.test'), 'web');
    // Required, an owner is held at setup like anyone else, so this one has enrolled.
    auth()->user()?->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

    $this->putJson(TF_SETTING_URL, ['required' => true])->assertOk();
    expect(AuditSensitive::query()->count())->toBe(0);

    $this->putJson(TF_SETTING_URL, ['required' => false])->assertOk()->assertJsonPath('required', false);
    expect(AuditSensitive::query()->count())->toBe(1);

    app('auth')->forgetGuards();
    $this->actingAs(tfSettingStaff(Role::Accountant, 'kamrul@toymartbd.test'), 'web');
    $this->getJson('/api/v1/admin/finance/expenses')->assertOk();
});

it('validates the body', function (): void {
    $this->actingAs(tfSettingStaff(Role::SuperAdmin, 'owner@toymartbd.test'), 'web');

    $this->putJson(TF_SETTING_URL, [])->assertUnprocessable()->assertJsonPath('errors.0.field', 'required');
    $this->putJson(TF_SETTING_URL, ['required' => 'maybe'])->assertUnprocessable();
});

it('seeds the setting off and never overwrites a saved choice', function (): void {
    $this->seed(SecuritySettingsSeeder::class);
    expect(Setting::get(TwoFactor::SETTING))->toBeFalse();

    Setting::put(TwoFactor::SETTING, true, SettingGroup::System);
    $this->seed(SecuritySettingsSeeder::class);
    expect(Setting::get(TwoFactor::SETTING))->toBeTrue();
});

describe('role matrix', function (): void {
    beforeEach(fn () => config(['auth.two_factor.enforce' => false]));

    it('guards GET /staff/two-factor with staff.view', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(TF_SETTING_URL));
    })->with(RoleMatrix::dataset(Permission::StaffView));

    it('guards PUT /staff/two-factor with staff.manage', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson(TF_SETTING_URL, ['required' => false]));
    })->with(RoleMatrix::dataset(Permission::StaffManage));
});
