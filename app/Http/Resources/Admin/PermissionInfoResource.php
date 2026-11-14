<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use App\Models\Enums\Permission;
use Illuminate\Http\Request;

/**
 * One row of the Roles & permissions matrix: the permission and the two flags the screen
 * shows — owner-only (never grantable to another role) and reveals-money (holders must use
 * two-factor sign-in).
 *
 * @property Permission $resource
 */
final class PermissionInfoResource extends ApiResource
{
    /** @return array{key: string, owner_only: bool, reveals_money: bool} */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->resource->value,
            'owner_only' => (bool) $this->resource->isOwnerOnly(),
            'reveals_money' => (bool) $this->resource->revealsMoney(),
        ];
    }
}
