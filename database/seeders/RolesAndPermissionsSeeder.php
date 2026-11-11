<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Enums\Permission as PermissionName;
use App\Models\Enums\Role as RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the 7 launch roles and the full permission set from the enums, which are the single
 * source of truth ({@see RoleName::permissions()}). Idempotent: safe to run on every deploy;
 * it creates what is missing, removes permissions no longer in the enum, and syncs each
 * launch role to exactly its matrix row.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const GUARD = 'web';

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, self::GUARD);
        }

        Permission::query()
            ->where('guard_name', self::GUARD)
            ->whereNotIn('name', PermissionName::values())
            ->delete();

        foreach (RoleName::cases() as $role) {
            Role::findOrCreate($role->value, self::GUARD)
                ->syncPermissions($role->permissionNames());
        }

        $registrar->forgetCachedPermissions();
    }
}
