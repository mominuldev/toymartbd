<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use App\Models\Enums\Role;
use Illuminate\Http\Request;

/**
 * One role in the read-only Roles & permissions matrix. Roles and their permissions are
 * defined in code ({@see Role::permissions()}) and seeded; the screen shows them, it does
 * not edit them.
 *
 * @property array{role: Role, users_count: int} $resource
 */
final class StaffRoleResource extends ApiResource
{
    /**
     * @return array{key: string, permissions: list<string>, users_count: int, bypasses_all: bool}
     */
    public function toArray(Request $request): array
    {
        $role = $this->resource['role'];

        return [
            'key' => $role->value,
            /** @var list<string> */
            'permissions' => $role->permissionNames(),
            'users_count' => (int) $this->resource['users_count'],
            /** super_admin passes every check (Gate::before), whatever is listed. */
            'bypasses_all' => $role === Role::SuperAdmin,
        ];
    }
}
