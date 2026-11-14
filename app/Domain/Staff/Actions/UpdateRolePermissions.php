<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Exceptions\StaffConflict;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sets what a role can do (owner decision 2026-11-14: roles editable in the admin). The new
 * list replaces the old. The owner role is never edited (it holds everything), and owner-only
 * permissions are never granted (the request refuses them; this re-checks). One
 * `role_permissions_changed` audit row lists what was added and removed. Holders feel the
 * change on their next request; the permission cache is dropped once the transaction has committed.
 */
final readonly class UpdateRolePermissions
{
    /**
     * @param  list<Permission>  $permissions
     * @return list<string> the role's permissions after the save, in enum order
     */
    public function handle(Role $role, array $permissions, ?int $actorId): array
    {
        if ($role === Role::SuperAdmin) {
            throw StaffConflict::ownerRole();
        }

        $wanted = array_values(array_map(
            static fn (Permission $p): string => $p->value,
            array_filter(Permission::cases(), static fn (Permission $p): bool => in_array($p, $permissions, true) && ! $p->isOwnerOnly()),
        ));

        $after = DB::transaction(function () use ($role, $wanted, $actorId): array {
            $model = RoleModel::query()->where('name', $role->value)->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
            /** @var list<string> $before */
            $before = $model->permissions()->pluck('name')->all();

            $added = array_values(array_diff($wanted, $before));
            $removed = array_values(array_diff($before, $wanted));
            if ($added === [] && $removed === []) {
                return $wanted;
            }

            $model->syncPermissions($wanted);

            AuditSensitive::query()->create([
                'user_id' => $actorId,
                'action' => AuditAction::RolePermissionsChanged,
                'model_type' => $model->getMorphClass(),
                'model_id' => $model->getKey(),
                'before' => ['role' => $role->value, 'removed' => $removed],
                'after' => ['role' => $role->value, 'added' => $added],
            ]);

            return $wanted;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $after;
    }
}
