<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * A staff account in the staff list. Deliberately minimal: no password hash,
 * remember token, timestamps or anything beyond name, email, roles and status.
 *
 * @property User $resource
 */
final class StaffResource extends ApiResource
{
    /**
     * @return array{id: int, name: string, email: string, is_active: bool, roles: list<string>}
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        /** @var list<string> $roles */
        $roles = $user->getRoleNames()->values()->all();

        return [
            'id' => (int) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            /** @var list<string> */
            'roles' => $roles,
        ];
    }
}
