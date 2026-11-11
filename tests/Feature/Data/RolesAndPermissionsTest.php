<?php

declare(strict_types=1);

use App\Models\Enums\Permission as PermissionName;
use App\Models\Enums\Role as RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('seeds exactly the 7 launch roles', function (): void {
    expect(Role::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(RoleName::values())->sort()->values()->all())
        ->and(Role::query()->count())->toBe(7);
});

it('seeds every permission in resource.action form', function (): void {
    expect(Permission::query()->count())->toBe(count(PermissionName::cases()));

    foreach (Permission::query()->pluck('name') as $name) {
        expect($name)->toMatch('/^[a-z_]+\.[a-z_]+$/');
    }
});

it('gives each role exactly its matrix permissions', function (RoleName $role): void {
    $seeded = Role::findByName($role->value, 'web')->permissions->pluck('name')->sort()->values()->all();
    $expected = collect($role->permissionNames())->sort()->values()->all();

    expect($seeded)->toBe($expected);

    $user = User::factory()->withRole($role)->create();

    foreach (PermissionName::cases() as $permission) {
        expect($user->hasPermissionTo($permission->value))
            ->toBe(in_array($permission, $role->permissions(), true), "{$role->value} / {$permission->value}");
    }
})->with(RoleName::cases());

it('pins the matrix shape so a change is deliberate', function (): void {
    $counts = collect(RoleName::cases())
        ->mapWithKeys(fn (RoleName $role): array => [$role->value => count($role->permissions())])
        ->all();

    expect($counts)->toBe([
        'super_admin' => 64,
        'manager' => 61,
        'order_operator' => 23,
        'inventory_staff' => 12,
        'accountant' => 20,
        'content_editor' => 9,
        'viewer' => 13,
    ]);
});

it('keeps PII to the roles that need it', function (): void {
    $withPii = collect(RoleName::cases())
        ->filter(fn (RoleName $role): bool => in_array(PermissionName::CustomersViewPii, $role->permissions(), true))
        ->map->value->values()->all();

    expect($withPii)->toBe(['super_admin', 'manager', 'order_operator']);

    $viewer = User::factory()->withRole(RoleName::Viewer)->create();

    expect($viewer->can('customers.view_pii'))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('customers.view_pii'))->toBeFalse()
        ->and($viewer->can('customers.view'))->toBeTrue();
});

it('reserves staff, role and credential management for super_admin', function (): void {
    foreach (RoleName::cases() as $role) {
        $holds = array_intersect(
            ['staff.manage', 'roles.manage', 'settings.manage_integrations'],
            $role->permissionNames(),
        );

        expect($holds === [] || $role === RoleName::SuperAdmin)->toBeTrue($role->value);
    }
});

it('lets only super_admin bypass gates via Gate::before', function (): void {
    Gate::define('never.granted', fn (): bool => false);

    $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create();

    expect($superAdmin->can('never.granted'))->toBeTrue()
        ->and($superAdmin->can('ability.that.does.not.exist'))->toBeTrue();

    foreach (array_filter(RoleName::cases(), fn (RoleName $r): bool => $r !== RoleName::SuperAdmin) as $role) {
        $user = User::factory()->withRole($role)->create();

        expect($user->can('never.granted'))->toBeFalse($role->value)
            ->and($user->can('ability.that.does.not.exist'))->toBeFalse($role->value);
    }

    $roleless = User::factory()->create();
    expect($roleless->can('never.granted'))->toBeFalse()
        ->and($roleless->can('orders.view'))->toBeFalse();
});

it('is idempotent', function (): void {
    $counts = fn (): array => [
        'roles' => Role::query()->count(),
        'permissions' => Permission::query()->count(),
        'role_has_permissions' => DB::table('role_has_permissions')->count(),
    ];

    $first = $counts();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    $expectedPivot = array_sum(array_map(fn (RoleName $r): int => count($r->permissions()), RoleName::cases()));

    expect($counts())->toBe($first)
        ->and($first['role_has_permissions'])->toBe($expectedPivot);
});

it('removes stale permissions and restores drifted roles on reseed', function (): void {
    Permission::findOrCreate('legacy.thing', 'web');
    Role::findByName(RoleName::Viewer->value, 'web')->givePermissionTo('finance.view');

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::query()->where('name', 'legacy.thing')->exists())->toBeFalse()
        ->and(Role::findByName(RoleName::Viewer->value, 'web')->hasPermissionTo('finance.view'))->toBeFalse();
});

it('is called from DatabaseSeeder', function (): void {
    DB::table('roles')->delete();
    $this->seed();

    expect(Role::query()->count())->toBe(7);
});

it('seeds through DatabaseSeeder from empty tables (migrate:fresh --seed)', function (): void {
    // Regression: DatabaseSeeder used WithoutModelEvents, which silenced the events spatie
    // uses to refresh its permission cache, so syncPermissions() found nothing on a fresh
    // database. Seeding the roles seeder on its own never showed it.
    Spatie\Permission\Models\Role::query()->delete();
    Spatie\Permission\Models\Permission::query()->delete();
    $registrar = app(Spatie\Permission\PermissionRegistrar::class);
    $registrar->forgetCachedPermissions();
    $registrar->getPermissions();

    $this->seed(Database\Seeders\DatabaseSeeder::class);

    expect(Spatie\Permission\Models\Role::findByName('viewer', 'web')->permissions)->not->toBeEmpty();
});
