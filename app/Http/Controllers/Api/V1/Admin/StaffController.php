<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Staff\Actions\CreateStaff;
use App\Domain\Staff\Actions\ResetStaffPassword;
use App\Domain\Staff\Actions\ResetStaffTwoFactor;
use App\Domain\Staff\Actions\SetStaffActive;
use App\Domain\Staff\Actions\UpdateRolePermissions;
use App\Domain\Staff\Actions\UpdateStaff;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Staff\ManageStaffRequest;
use App\Http\Requests\Admin\Staff\ResetStaffPasswordRequest;
use App\Http\Requests\Admin\Staff\StoreStaffRequest;
use App\Http\Requests\Admin\Staff\UpdateRolePermissionsRequest;
use App\Http\Requests\Admin\Staff\UpdateStaffRequest;
use App\Http\Resources\Admin\StaffResource;
use App\Http\Resources\Admin\StaffRoleMatrixResource;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Settings → Staff & roles (Figma B · 35 `241:48916`). Reading needs staff.view;
 * every write needs staff.manage (super_admin only). Staff are deactivated, never deleted.
 * Writes that would change yourself or leave no active owner are a 409 `conflict`.
 */
final class StaffController extends ApiController
{
    public const int PER_PAGE = 25;

    /** `GET /staff` — cursor-paginated `{ data, links, meta }`, needs staff.view. */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $staff = User::query()
            ->with(['roles.permissions', 'permissions'])
            ->orderBy('id')
            ->cursorPaginate(self::PER_PAGE);

        return StaffResource::collection($staff);
    }

    /**
     * `GET /staff/roles` — the Roles & permissions matrix: every role with what it holds now,
     * its defaults and how many active staff hold it, plus every permission's flags; needs
     * staff.view.
     */
    public function roles(): StaffRoleMatrixResource
    {
        $this->authorize('viewAny', User::class);

        return new StaffRoleMatrixResource($this->roleRows());
    }

    /**
     * `PUT /staff/roles/{role}` — replace what a role can do; needs roles.manage. Audited
     * (`role_permissions_changed`). Returns the whole matrix, as `GET /staff/roles`.
     */
    #[OpenApiResponse(409, 'The owner role cannot be edited (`conflict`)')]
    public function updateRole(UpdateRolePermissionsRequest $request, UpdateRolePermissions $update): StaffRoleMatrixResource
    {
        $update->handle($request->role(), $request->permissions(), $this->actorId($request));

        return new StaffRoleMatrixResource($this->roleRows());
    }

    /** `POST /staff/{user}/two-factor/reset` — clear someone else's two-factor (lost phone). */
    #[OpenApiResponse(409, 'Your own account (`conflict`)')]
    public function resetTwoFactor(ManageStaffRequest $request, User $user, ResetStaffTwoFactor $reset): StaffResource
    {
        return new StaffResource($reset->handle($user, $this->actorId($request)));
    }

    /** @return list<array{role: Role, permissions: list<string>, users_count: int}> */
    private function roleRows(): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('users.is_active', true)
            ->groupBy('roles.name')
            ->selectRaw('roles.name as name, count(*) as aggregate')
            ->pluck('aggregate', 'name')
            ->map(static fn (mixed $n): int => (int) $n)
            ->all();

        /** @var array<string, list<string>> $held */
        $held = [];
        foreach (DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->get(['roles.name as role', 'permissions.name as permission']) as $row) {
            $held[(string) $row->role][] = (string) $row->permission;
        }

        return array_map(static fn (Role $role): array => [
            'role' => $role,
            // Enum order, whatever order the pivot returns.
            'permissions' => array_values(array_filter(Permission::values(), static fn (string $p): bool => in_array($p, $held[$role->value] ?? [], true))),
            'users_count' => $counts[$role->value] ?? 0,
        ], Role::cases());
    }

    /** `POST /staff` — add a staff account with one role and a temporary password. */
    public function store(StoreStaffRequest $request, CreateStaff $create): StaffResource
    {
        return new StaffResource($create->handle($request->toData(), $this->actorId($request)));
    }

    /** `PATCH /staff/{user}` — rename and/or change the role. */
    #[OpenApiResponse(409, 'Your own role, or the last active owner (`conflict`)')]
    public function update(UpdateStaffRequest $request, User $user, UpdateStaff $update): StaffResource
    {
        return new StaffResource($update->handle($user, $request->name(), $request->role(), $this->actorId($request)));
    }

    /** `POST /staff/{user}/deactivate` — they cannot sign in; history keeps their name. */
    #[OpenApiResponse(409, 'Yourself, or the last active owner (`conflict`)')]
    public function deactivate(ManageStaffRequest $request, User $user, SetStaffActive $set): StaffResource
    {
        return new StaffResource($set->handle($user, false, $this->actorId($request)));
    }

    /** `POST /staff/{user}/reactivate`. */
    #[OpenApiResponse(409, 'Yourself (`conflict`)')]
    public function reactivate(ManageStaffRequest $request, User $user, SetStaffActive $set): StaffResource
    {
        return new StaffResource($set->handle($user, true, $this->actorId($request)));
    }

    /** `POST /staff/{user}/password` — a new temporary password for someone else. 204. */
    #[OpenApiResponse(409, 'Your own password (`conflict`)')]
    public function resetPassword(ResetStaffPasswordRequest $request, User $user, ResetStaffPassword $reset): Response
    {
        $reset->handle($user, $request->password(), $this->actorId($request));

        return response()->noContent();
    }

    private function actorId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
