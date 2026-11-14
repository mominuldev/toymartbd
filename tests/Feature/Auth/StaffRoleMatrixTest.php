<?php

declare(strict_types=1);

use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Support\RoleMatrix;

/*
 * The 403 role matrix (P3-API-06, CLAUDE.md rule 21) proven on real endpoints.
 */

it('guards GET /staff with staff.view', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/staff'));
})->with(RoleMatrix::dataset(Permission::StaffView));

it('lets every active staff role read /auth/me', function (): void {
    RoleMatrix::assert($this, null, fn () => $this->getJson('/api/v1/admin/auth/me'));
});

it('derives the matrix from Role::permissions()', function (): void {
    $allowed = collect(RoleMatrix::dataset(Permission::StaffView))
        ->filter(fn (array $row): bool => $row[1])
        ->map(fn (array $row): string => $row[0]->value)
        ->values()
        ->all();

    // staff.view: super_admin and manager only; staff.manage: super_admin only.
    expect($allowed)->toBe(['super_admin', 'manager'])
        ->and(collect(Role::cases())->filter(fn (Role $r): bool => RoleMatrix::allows($r, Permission::StaffManage))->map->value->values()->all())
        ->toBe(['super_admin']);
});

it('lets super_admin through via Gate::before even with no permissions attached', function (): void {
    // Deliberately NOT seeded: the role exists but holds no permissions at all.
    RoleModel::create(['name' => Role::SuperAdmin->value, 'guard_name' => 'web']);
    $user = User::factory()->create()->refresh();
    $user->assignRole(Role::SuperAdmin);

    expect($user->getAllPermissions())->toBeEmpty();

    $this->actingAs($user, 'web')->getJson('/api/v1/admin/staff')->assertOk();
});

it('lists staff as a cursor-paginated collection with no PII beyond name, email, roles and sign-in times', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    User::factory()->count(30)->withRole(Role::Viewer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create()->refresh();

    $response = $this->actingAs($manager, 'web')->getJson('/api/v1/admin/staff')->assertOk();

    $response->assertJsonStructure(['data', 'links', 'meta' => ['next_cursor', 'prev_cursor', 'per_page']])
        ->assertJsonCount(25, 'data')
        ->assertJsonPath('meta.per_page', 25)
        ->assertJsonPath('data.0.roles', ['viewer']);

    expect(array_keys($response->json('data.0')))->toBe(['id', 'name', 'email', 'is_active', 'roles', 'last_login_at', 'created_at', 'is_self', 'two_factor_enabled', 'two_factor_required'])
        ->and($response->json('meta.next_cursor'))->not->toBeNull();

    $next = $this->getJson('/api/v1/admin/staff?cursor='.$response->json('meta.next_cursor'))->assertOk();

    expect($next->json('data'))->toHaveCount(6)
        ->and($next->json('meta.next_cursor'))->toBeNull();
});

it('answers /staff with 401 for a guest', function (): void {
    $this->getJson('/api/v1/admin/staff')
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/unauthenticated');
});
