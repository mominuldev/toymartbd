<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `GET /staff/roles` — the whole Roles & permissions matrix: every role (enum order) and every
 * permission (enum order) with the two flags the screen needs: owner-only (never grantable to
 * another role) and reveals-money (holders must use two-factor sign-in).
 *
 * @property list<array{role: Role, permissions: list<string>, users_count: int}> $resource
 */
final class StaffRoleMatrixResource extends ApiResource
{
    /**
     * @return array{roles: AnonymousResourceCollection, permissions: AnonymousResourceCollection}
     */
    public function toArray(Request $request): array
    {
        return [
            'roles' => StaffRoleResource::collection($this->resource),
            'permissions' => PermissionInfoResource::collection(Permission::cases()),
        ];
    }
}
