<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Staff;

use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `PUT /staff/roles/{role}` — `{ permissions: string[] }`, the role's complete new list;
 * needs roles.manage. Owner-only permissions (staff.manage, roles.manage) are refused, and
 * the owner role itself is not editable (409 from the Action).
 */
final class UpdateRolePermissionsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::RolesManage->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $grantable = array_values(array_map(
            static fn (Permission $p): string => $p->value,
            array_filter(Permission::cases(), static fn (Permission $p): bool => ! $p->isOwnerOnly()),
        ));

        return [
            'permissions' => ['present', 'array', 'max:'.count(Permission::cases())],
            'permissions.*' => ['string', 'distinct', Rule::in($grantable)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['permissions.*.in' => (string) __('staff.owner_only_permission')];
    }

    /** An unknown role is a 404 — after the auth check, so a guest still gets 401. */
    public function role(): Role
    {
        return Role::tryFrom((string) $this->route('role')) ?? throw new NotFoundHttpException;
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        /** @var list<string> $names */
        $names = $this->input('permissions', []);

        return array_map(static fn (string $name): Permission => Permission::from($name), $names);
    }
}
