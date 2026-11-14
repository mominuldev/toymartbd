<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SetStaffActive;
use App\Domain\Staff\Actions\UpdateStaff;
use App\Domain\Staff\Exceptions\StaffConflict;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RoleMatrix;

/*
 * Admin → Settings → Staff & roles (Figma B · 35). Reading needs staff.view; every write
 * needs staff.manage. Staff are deactivated, never deleted. Nobody can lock the business
 * out: no changes to yourself, and the last active owner stays an active owner.
 */

const STAFF_URL = '/api/v1/admin/staff';
const TEMP_PASSWORD = 'temporary-pass-1234';

function staffOwner(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $owner = User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
    test()->actingAs($owner, 'web');

    return $owner;
}

/** @return array<string, mixed> */
function newStaff(array $overrides = []): array
{
    return [
        'name' => 'Rina Akter',
        'email' => 'Rina@ToyMartBD.test',
        'role' => 'order_operator',
        'password' => TEMP_PASSWORD,
        ...$overrides,
    ];
}

it('adds a staff member with one role, a lower-cased email and a hashed password', function (): void {
    $owner = staffOwner();

    $response = $this->postJson(STAFF_URL, newStaff())->assertCreated()
        ->assertJsonPath('email', 'rina@toymartbd.test')
        ->assertJsonPath('roles', ['order_operator'])
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('is_self', false)
        ->assertJsonPath('last_login_at', null);

    expect($response->json())->not->toHaveKey('password');

    $user = User::query()->where('email', 'rina@toymartbd.test')->sole();
    expect(Hash::check(TEMP_PASSWORD, $user->password))->toBeTrue()
        ->and($user->hasPermissionTo(Permission::OrdersConfirm->value))->toBeTrue();

    $audit = AuditSensitive::query()->sole();
    expect($audit->action)->toBe(AuditAction::RoleAssigned)
        ->and($audit->user_id)->toBe($owner->id)
        ->and($audit->model_id)->toBe($user->id)
        ->and($audit->after)->toBe(['roles' => ['order_operator'], 'created' => true])
        ->and(json_encode($audit->getAttributes()))->not->toContain(TEMP_PASSWORD);
});

it('refuses a duplicate email (any case), an unknown role and a short password', function (): void {
    staffOwner();
    User::factory()->create(['email' => 'rina@toymartbd.test']);

    $this->postJson(STAFF_URL, newStaff(['email' => 'RINA@toymartbd.test', 'role' => 'cashier', 'password' => 'short']))
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/validation-error')
        ->assertJsonFragment(['field' => 'email'])
        ->assertJsonFragment(['field' => 'role'])
        ->assertJsonFragment(['field' => 'password']);

    expect(AuditSensitive::query()->count())->toBe(0);
});

it('changes a role and audits before and after', function (): void {
    staffOwner();
    $staff = User::factory()->withRole(Role::Viewer)->create();

    $this->patchJson(STAFF_URL.'/'.$staff->id, ['role' => 'accountant', 'name' => 'Kamrul Bari'])
        ->assertOk()
        ->assertJsonPath('roles', ['accountant'])
        ->assertJsonPath('name', 'Kamrul Bari');

    $audit = AuditSensitive::query()->sole();
    expect($audit->before)->toBe(['roles' => ['viewer']])
        ->and($audit->after)->toBe(['roles' => ['accountant']]);
});

it('does not audit a rename alone, nor a role set to what it already is', function (): void {
    staffOwner();
    $staff = User::factory()->withRole(Role::Viewer)->create();

    $this->patchJson(STAFF_URL.'/'.$staff->id, ['name' => 'New Name', 'role' => 'viewer'])->assertOk();

    expect(AuditSensitive::query()->count())->toBe(0)
        ->and($staff->refresh()->name)->toBe('New Name');
});

it('refuses to change your own role with a 409', function (): void {
    $owner = staffOwner();
    User::factory()->withRole(Role::SuperAdmin)->create();

    $this->patchJson(STAFF_URL.'/'.$owner->id, ['role' => 'viewer'])
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/conflict');

    expect($owner->refresh()->hasRole(Role::SuperAdmin->value))->toBeTrue();
});

it('lets you rename yourself', function (): void {
    $owner = staffOwner();

    $this->patchJson(STAFF_URL.'/'.$owner->id, ['name' => 'Mominul Islam'])->assertOk()->assertJsonPath('is_self', true);
});

it('never leaves the shop without an active owner', function (): void {
    $owner = staffOwner();
    $second = User::factory()->withRole(Role::SuperAdmin)->create();

    // Two active owners: demoting or deactivating one of them is fine.
    $this->patchJson(STAFF_URL.'/'.$second->id, ['role' => 'manager'])->assertOk();
    $this->patchJson(STAFF_URL.'/'.$second->id, ['role' => 'super_admin'])->assertOk();
    $this->postJson(STAFF_URL.'/'.$second->id.'/deactivate')->assertOk()->assertJsonPath('is_active', false);

    // Now $owner is the only ACTIVE owner ($second is an owner but inactive, which does
    // not count). Only an owner can manage staff and nobody may change themselves, so the
    // last-owner guard is exercised through the Actions, as another actor would reach it.
    expect(fn () => app(SetStaffActive::class)->handle($owner->refresh(), false, $second->id))
        ->toThrow(StaffConflict::class)
        ->and(fn () => app(UpdateStaff::class)->handle($owner->refresh(), null, Role::Manager, $second->id))
        ->toThrow(StaffConflict::class)
        ->and($owner->refresh()->is_active)->toBeTrue()
        ->and($owner->hasRole(Role::SuperAdmin->value))->toBeTrue();
});

it('deactivates: no sign-in, tokens revoked, audited, then reactivates', function (): void {
    staffOwner();
    $staff = User::factory()->withRole(Role::OrderOperator)->create(['email' => 'tanvir@toymartbd.test', 'password' => TEMP_PASSWORD]);
    $staff->createToken('device');

    $this->postJson(STAFF_URL.'/'.$staff->id.'/deactivate')->assertOk()->assertJsonPath('is_active', false);
    expect($staff->tokens()->count())->toBe(0);

    $revoked = AuditSensitive::query()->sole();
    expect($revoked->action)->toBe(AuditAction::RoleRevoked)
        ->and($revoked->after)->toBe(['is_active' => false, 'roles' => ['order_operator']]);

    // Deactivating again is a no-op, not a second audit row.
    $this->postJson(STAFF_URL.'/'.$staff->id.'/deactivate')->assertOk();
    expect(AuditSensitive::query()->count())->toBe(1);

    $this->postJson(STAFF_URL.'/'.$staff->id.'/reactivate')->assertOk()->assertJsonPath('is_active', true);
    expect(AuditSensitive::query()->latest('id')->first()?->action)->toBe(AuditAction::RoleAssigned);
});

it('refuses to deactivate yourself', function (): void {
    $owner = staffOwner();
    User::factory()->withRole(Role::SuperAdmin)->create();

    $this->postJson(STAFF_URL.'/'.$owner->id.'/deactivate')->assertStatus(409);
    expect($owner->refresh()->is_active)->toBeTrue();
});

it('sets a new temporary password for someone else, audited without the value', function (): void {
    staffOwner();
    $staff = User::factory()->withRole(Role::Accountant)->create();
    $staff->createToken('device');

    $this->postJson(STAFF_URL.'/'.$staff->id.'/password', ['password' => 'a-brand-new-pass-99'])->assertNoContent();

    expect(Hash::check('a-brand-new-pass-99', $staff->refresh()->password))->toBeTrue()
        ->and($staff->tokens()->count())->toBe(0);

    $audit = AuditSensitive::query()->sole();
    expect($audit->action)->toBe(AuditAction::CredentialChanged)
        ->and($audit->after)->toBe(['scope' => 'staff_password', 'password' => 'changed'])
        ->and(json_encode($audit->getAttributes()))->not->toContain('a-brand-new-pass-99');

    $this->postJson(STAFF_URL.'/'.$staff->id.'/password', ['password' => 'short'])->assertStatus(422);
});

it('refuses to reset your own password here', function (): void {
    $owner = staffOwner();

    $this->postJson(STAFF_URL.'/'.$owner->id.'/password', ['password' => 'a-brand-new-pass-99'])->assertStatus(409);
});

it('lists every role with its permissions and active holder count, in enum order', function (): void {
    staffOwner();
    User::factory()->count(2)->withRole(Role::OrderOperator)->create();
    User::factory()->withRole(Role::OrderOperator)->create(['is_active' => false]);

    $response = $this->getJson(STAFF_URL.'/roles')->assertOk();

    expect(array_column($response->json('roles'), 'key'))->toBe(Role::values())
        ->and($response->json('roles.0'))->toMatchArray(['key' => 'super_admin', 'users_count' => 1, 'bypasses_all' => true, 'editable' => false])
        ->and($response->json('roles.2.key'))->toBe('order_operator')
        ->and($response->json('roles.2.users_count'))->toBe(2)
        ->and($response->json('roles.2.permissions'))->toBe(Role::OrderOperator->permissionNames())
        ->and($response->json('roles.2.default_permissions'))->toBe(Role::OrderOperator->permissionNames())
        ->and($response->json('roles.2.warnings'))->toBe([])
        ->and($response->json('roles.6.users_count'))->toBe(0)
        ->and(array_column($response->json('permissions'), 'key'))->toBe(Permission::values())
        ->and(collect($response->json('permissions'))->where('owner_only', true)->pluck('key')->all())->toBe(['staff.manage', 'roles.manage'])
        ->and(collect($response->json('permissions'))->firstWhere('key', 'finance.view')['reveals_money'])->toBeTrue()
        ->and(collect($response->json('permissions'))->firstWhere('key', 'orders.view')['reveals_money'])->toBeFalse();
});

it('404s an unknown staff id', function (): void {
    staffOwner();

    $this->patchJson(STAFF_URL.'/999999', ['name' => 'Nobody'])->assertNotFound();
});

it('guards GET /staff/roles with staff.view', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(STAFF_URL.'/roles'));
})->with(RoleMatrix::dataset(Permission::StaffView));

it('guards POST /staff with staff.manage', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(STAFF_URL, newStaff(['email' => 'matrix-'.$role->value.'@toymartbd.test'])), 201);
})->with(RoleMatrix::dataset(Permission::StaffManage));

it('guards PATCH /staff/{user} with staff.manage', function (Role $role, bool $allowed): void {
    (new RolesAndPermissionsSeeder)->run();
    $target = User::factory()->withRole(Role::Viewer)->create();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->patchJson(STAFF_URL.'/'.$target->id, ['role' => 'accountant']));
})->with(RoleMatrix::dataset(Permission::StaffManage));

it('guards POST /staff/{user}/deactivate with staff.manage', function (Role $role, bool $allowed): void {
    (new RolesAndPermissionsSeeder)->run();
    $target = User::factory()->withRole(Role::Viewer)->create();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(STAFF_URL.'/'.$target->id.'/deactivate'));
})->with(RoleMatrix::dataset(Permission::StaffManage));

it('guards POST /staff/{user}/reactivate with staff.manage', function (Role $role, bool $allowed): void {
    (new RolesAndPermissionsSeeder)->run();
    $target = User::factory()->withRole(Role::Viewer)->create(['is_active' => false]);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(STAFF_URL.'/'.$target->id.'/reactivate'));
})->with(RoleMatrix::dataset(Permission::StaffManage));

it('guards POST /staff/{user}/password with staff.manage', function (Role $role, bool $allowed): void {
    (new RolesAndPermissionsSeeder)->run();
    $target = User::factory()->withRole(Role::Viewer)->create();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(STAFF_URL.'/'.$target->id.'/password', ['password' => 'a-brand-new-pass-99']), 204);
})->with(RoleMatrix::dataset(Permission::StaffManage));
