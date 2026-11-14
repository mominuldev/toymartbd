<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Staff\Services\TwoFactor;
use App\Http\Resources\ApiResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * A staff account in the staff list. Deliberately minimal: no password hash, remember
 * token or anything beyond name, email, roles, status and when they last signed in.
 * `is_self` lets the screen hide actions the API would refuse on yourself. `two_factor_required`
 * is true when their role can see money (whether or not enforcement is switched on here).
 *
 * @property User $resource
 */
final class StaffResource extends ApiResource
{
    /**
     * @return array{id: int, name: string, email: string, is_active: bool, roles: list<string>, last_login_at: string|null, created_at: string|null, is_self: bool, two_factor_enabled: bool, two_factor_required: bool}
     */
    public function toArray(Request $request): array
    {
        // A list eager-loads these (StaffController::index); a single answer loads them here.
        $user = $this->resource->loadMissing(['roles.permissions', 'permissions']);

        /** @var list<string> $roles */
        $roles = $user->getRoleNames()->values()->all();

        return [
            'id' => (int) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            /** @var list<string> */
            'roles' => $roles,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'is_self' => $request->user()?->getAuthIdentifier() === $user->getKey(),
            'two_factor_enabled' => $user->hasTwoFactor(),
            'two_factor_required' => (bool) app(TwoFactor::class)->seesMoney($user),
        ];
    }
}
