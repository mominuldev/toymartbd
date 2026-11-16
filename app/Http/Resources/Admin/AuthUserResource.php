<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\BranchMode;
use App\Domain\Staff\Services\TwoFactor;
use App\Http\Resources\ApiResource;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The signed-in staff member, for `GET /auth/me` and `POST /auth/login`:
 * `{ id, name, email, locale, roles[], permissions[], two_factor, branches }`.
 *
 * `permissions` is the effective list the SPA uses to show or hide UI — never to
 * authorise; the API re-checks every request. super_admin gets every Permission,
 * matching its Gate::before bypass. Both lists are in enum declaration order.
 *
 * @property User $resource
 */
final class AuthUserResource extends ApiResource
{
    /**
     * @return array{id: int, name: string, email: string, locale: string|null, roles: list<string>, permissions: list<string>, two_factor: array{enabled: bool, required: bool}, branches: array{multi_branch: bool, locations: list<array{id: int, name: string, name_bn: string|null, area: string|null, type: string|null, is_default: bool}>}}
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $roleNames = $user->getRoleNames()->all();

        $roles = array_values(array_filter(
            Role::values(),
            static fn (string $role): bool => in_array($role, $roleNames, true),
        ));

        if (in_array(Role::SuperAdmin->value, $roles, true)) {
            $permissions = Permission::values();
        } else {
            $held = $user->getAllPermissions()->pluck('name')->all();
            $permissions = array_values(array_filter(
                Permission::values(),
                static fn (string $permission): bool => in_array($permission, $held, true),
            ));
        }

        $locale = $user->getAttribute('locale');

        return [
            'id' => (int) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            /**
             * No `users.locale` column yet; null means "use the request locale".
             *
             * @var 'en'|'bn'|null
             */
            'locale' => is_string($locale) ? $locale : null,
            /** @var list<string> */
            'roles' => $roles,
            /** @var list<string> */
            'permissions' => $permissions,
            /**
             * `required`: enforcement is on and this person can see money. The SPA sends someone
             * who is required but not `enabled` to the setup screen; the API refuses everything
             * else with 403 `two-factor-required` meanwhile.
             */
            'two_factor' => [
                'enabled' => $user->hasTwoFactor(),
                'required' => (bool) app(TwoFactor::class)->requiredFor($user),
            ],
            /**
             * Single shop or multiple branches, and the active locations the sidebar's branch
             * switcher offers (every staff member gets them: the working branch is only the
             * default for POS, purchases, adjustments and transfers). Names and area only — no
             * address or contact details.
             */
            'branches' => [
                'multi_branch' => BranchMode::isMulti(),
                /** @var list<array{id: int, name: string, name_bn: string|null, area: string|null, type: 'warehouse'|'retail'|'pickup'|null, is_default: bool}> */
                'locations' => array_map(static fn (StockLocation $l): array => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'name_bn' => $l->name_bn,
                    'area' => is_string($l->area) && trim($l->area) !== '' ? $l->area : null,
                    'type' => $l->type?->value,
                    'is_default' => (bool) $l->is_default,
                ], BranchMode::activeLocations()),
            ],
        ];
    }
}
