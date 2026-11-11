<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The signed-in staff member, for `GET /auth/me` and `POST /auth/login`:
 * `{ id, name, email, locale, roles[], permissions[] }`.
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
     * @return array{id: int, name: string, email: string, locale: string|null, roles: list<string>, permissions: list<string>}
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
        ];
    }
}
