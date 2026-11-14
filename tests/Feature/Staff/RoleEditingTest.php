<?php

declare(strict_types=1);

use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Support\RoleMatrix;

/*
 * Editable roles (owner decision 2026-11-14). An owner sets what each role can do; the owner
 * role and the owner-only permissions are fixed; every change is audited; a deploy's seeder
 * run keeps the owner's edits.
 */

const ROLES_URL = '/api/v1/admin/staff/roles';

function roleEditor(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $owner = User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
    test()->actingAs($owner, 'web');

    return $owner;
}

/** @return list<string> */
function heldBy(Role $role): array
{
    return RoleModel::findByName($role->value, 'web')->permissions()->pluck('name')->sort()->values()->all();
}

it('replaces a role\'s permissions and audits what was added and removed', function (): void {
    $owner = roleEditor();
    $wanted = [...array_values(array_diff(Role::Viewer->permissionNames(), ['seo.view'])), 'finance.view'];

    $response = $this->putJson(ROLES_URL.'/viewer', ['permissions' => $wanted])->assertOk();

    $viewer = collect($response->json('roles'))->firstWhere('key', 'viewer');
    expect($viewer['permissions'])->toContain('finance.view')->not->toContain('seo.view')
        ->and($viewer['default_permissions'])->toBe(Role::Viewer->permissionNames())
        ->and(heldBy(Role::Viewer))->toBe(collect($wanted)->sort()->values()->all());

    $audit = AuditSensitive::query()->sole();
    expect($audit->action)->toBe(AuditAction::RolePermissionsChanged)
        ->and($audit->user_id)->toBe($owner->id)
        ->and($audit->before)->toBe(['role' => 'viewer', 'removed' => ['seo.view']])
        ->and($audit->after)->toBe(['role' => 'viewer', 'added' => ['finance.view']]);
});

it('changes access on the holder\'s very next request', function (): void {
    roleEditor();
    $viewer = User::factory()->withRole(Role::Viewer)->create()->refresh();

    auth()->forgetGuards();
    $this->actingAs($viewer, 'web')->getJson('/api/v1/admin/finance/expenses')->assertForbidden();

    $owner = User::query()->whereKeyNot($viewer->id)->sole();
    auth()->forgetGuards();
    $this->actingAs($owner, 'web')->putJson(ROLES_URL.'/viewer', ['permissions' => [...Role::Viewer->permissionNames(), 'finance.view']])->assertOk();

    auth()->forgetGuards();
    $this->actingAs($viewer->fresh(), 'web')->getJson('/api/v1/admin/finance/expenses')->assertOk();
});

it('writes nothing when the list is unchanged', function (): void {
    roleEditor();

    $this->putJson(ROLES_URL.'/accountant', ['permissions' => Role::Accountant->permissionNames()])->assertOk();

    expect(AuditSensitive::query()->count())->toBe(0);
});

it('never grants an owner-only permission', function (string $permission): void {
    roleEditor();

    $this->putJson(ROLES_URL.'/manager', ['permissions' => [...Role::Manager->permissionNames(), $permission]])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.message', __('staff.owner_only_permission'));

    expect(heldBy(Role::Manager))->not->toContain($permission);
})->with(['staff.manage', 'roles.manage']);

it('refuses to edit the owner role', function (): void {
    roleEditor();

    $this->putJson(ROLES_URL.'/super_admin', ['permissions' => []])
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/conflict');

    expect(heldBy(Role::SuperAdmin))->toHaveCount(count(Permission::cases()));
});

it('refuses unknown permissions, duplicates and unknown roles', function (): void {
    roleEditor();

    $this->putJson(ROLES_URL.'/viewer', ['permissions' => ['orders.view', 'orders.view', 'nuclear.launch']])->assertStatus(422);
    $this->putJson(ROLES_URL.'/cashier', ['permissions' => []])->assertNotFound();
});

it('can empty a role', function (): void {
    roleEditor();

    $this->putJson(ROLES_URL.'/content_editor', ['permissions' => []])->assertOk();

    expect(heldBy(Role::ContentEditor))->toBe([]);
});

it('warns about separation-of-duties pairs the default does not combine, and not the manager\'s', function (): void {
    roleEditor();

    $response = $this->putJson(ROLES_URL.'/order_operator', ['permissions' => [...Role::OrderOperator->permissionNames(), 'refunds.approve']])->assertOk();

    expect(collect($response->json('roles'))->firstWhere('key', 'order_operator')['warnings'])->toBe([['refunds.create', 'refunds.approve']])
        ->and(collect($response->json('roles'))->firstWhere('key', 'manager')['warnings'])->toBe([]);
});

it('keeps the owner\'s edits when the seeder runs again on deploy', function (): void {
    roleEditor();
    $this->putJson(ROLES_URL.'/viewer', ['permissions' => ['dashboard.view', 'finance.view']])->assertOk();

    (new RolesAndPermissionsSeeder)->run();

    expect(heldBy(Role::Viewer))->toBe(['dashboard.view', 'finance.view']);
});

it('grants a permission new to the enum to the roles whose default includes it', function (): void {
    roleEditor();
    $this->putJson(ROLES_URL.'/viewer', ['permissions' => ['dashboard.view']])->assertOk();
    // Simulate a release that introduces orders.view: it does not exist yet in this database.
    PermissionModel::findByName('orders.view', 'web')->delete();

    (new RolesAndPermissionsSeeder)->run();

    expect(heldBy(Role::Viewer))->toBe(['dashboard.view', 'orders.view'])
        ->and(heldBy(Role::OrderOperator))->toContain('orders.view')
        ->and(heldBy(Role::ContentEditor))->not->toContain('orders.view');
});

it('strips an owner-only permission that reached another role outside the editor', function (): void {
    roleEditor();
    RoleModel::findByName('manager', 'web')->givePermissionTo('roles.manage');

    (new RolesAndPermissionsSeeder)->run();

    expect(heldBy(Role::Manager))->not->toContain('roles.manage');
});

it('guards PUT /staff/roles/{role} with roles.manage', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson(ROLES_URL.'/viewer', ['permissions' => Role::Viewer->permissionNames()]));
})->with(RoleMatrix::dataset(Permission::RolesManage));
