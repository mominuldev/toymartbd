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
 * Seeds the 7 launch roles and the permission set from the enums. Idempotent and safe on
 * every deploy — and since roles became editable (owner decision 2026-11-14) it never
 * overwrites the owner's edits:
 *
 * - a permission it CREATES is granted to every role whose default includes it;
 * - a role it CREATES gets exactly its default ({@see RoleName::permissions()});
 * - an existing role keeps whatever the owner set, minus owner-only permissions;
 * - super_admin always holds everything; permissions no longer in the enum are removed.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const GUARD = 'web';

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        /** @var list<string> $created */
        $created = [];
        foreach (PermissionName::cases() as $permission) {
            $model = Permission::findOrCreate($permission->value, self::GUARD);
            if ($model->wasRecentlyCreated) {
                $created[] = $permission->value;
            }
        }

        Permission::query()
            ->where('guard_name', self::GUARD)
            ->whereNotIn('name', PermissionName::values())
            ->delete();

        $ownerOnly = array_values(array_map(
            static fn (PermissionName $p): string => $p->value,
            array_filter(PermissionName::cases(), static fn (PermissionName $p): bool => $p->isOwnerOnly()),
        ));

        foreach (RoleName::cases() as $role) {
            $model = Role::findOrCreate($role->value, self::GUARD);

            if ($role === RoleName::SuperAdmin || $model->wasRecentlyCreated) {
                $model->syncPermissions($role->permissionNames());

                continue;
            }

            $new = array_values(array_intersect($created, $role->permissionNames()));
            if ($new !== []) {
                $model->givePermissionTo($new);
            }
            $model->revokePermissionTo(array_values(array_intersect($ownerOnly, $model->permissions->pluck('name')->all())));
        }

        $registrar->forgetCachedPermissions();
    }
}
