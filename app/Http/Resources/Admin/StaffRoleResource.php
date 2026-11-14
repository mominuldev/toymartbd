<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Staff\Services\SeparationOfDuties;
use App\Http\Resources\ApiResource;
use App\Models\Enums\Role;
use Illuminate\Http\Request;

/**
 * One role column of the Roles & permissions matrix. `permissions` is what the role holds now
 * (the database, editable by an owner since 2026-11-14); `default_permissions` is what it
 * starts with ({@see Role::permissions()}), so the screen can show and restore the defaults.
 * `warnings` lists separation-of-duties pairs the role holds that its default does not.
 *
 * @property array{role: Role, permissions: list<string>, users_count: int} $resource
 */
final class StaffRoleResource extends ApiResource
{
    /**
     * @return array{key: string, permissions: list<string>, default_permissions: list<string>, users_count: int, bypasses_all: bool, editable: bool, warnings: list<array{string, string}>}
     */
    public function toArray(Request $request): array
    {
        $role = $this->resource['role'];
        $permissions = $role === Role::SuperAdmin ? $role->permissionNames() : $this->resource['permissions'];

        return [
            'key' => $role->value,
            /** @var list<string> */
            'permissions' => $permissions,
            /** @var list<string> */
            'default_permissions' => $role->permissionNames(),
            'users_count' => (int) $this->resource['users_count'],
            /** super_admin passes every check (Gate::before), whatever is listed. */
            'bypasses_all' => $role === Role::SuperAdmin,
            /** Every role but the owner's can be edited by someone with roles.manage. */
            'editable' => $role !== Role::SuperAdmin,
            /**
             * Separation-of-duties pairs held together that the default does not combine.
             *
             * @var list<array{string, string}>
             */
            'warnings' => SeparationOfDuties::introduced($permissions, $role->permissionNames()),
        ];
    }
}
