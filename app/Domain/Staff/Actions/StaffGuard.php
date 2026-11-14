<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Exceptions\StaffConflict;
use App\Models\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The lock-out guards every staff write shares. Callers run it INSIDE their transaction:
 * the active owners are read `FOR UPDATE` (ascending id), so two owners demoting each
 * other at the same moment cannot both succeed and leave nobody able to manage staff.
 */
final readonly class StaffGuard
{
    /** Refuses a change to the signed-in user's own role or status. */
    public function notSelf(User $target, ?int $actorId): void
    {
        if ($actorId !== null && (int) $target->getKey() === $actorId) {
            throw StaffConflict::self();
        }
    }

    /**
     * Refuses a change that would take `$target` out of the active owners when it is the
     * last one. Call only when the change removes owner access (demote or deactivate).
     */
    public function keepsAnOwner(User $target): void
    {
        $ownerIds = DB::table('users')
            ->join('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', (new User)->getMorphClass());
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', Role::SuperAdmin->value)
            ->where('users.is_active', true)
            ->orderBy('users.id')
            ->lockForUpdate()
            ->pluck('users.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $remaining = array_diff($ownerIds, [(int) $target->getKey()]);

        if (in_array((int) $target->getKey(), $ownerIds, true) && $remaining === []) {
            throw StaffConflict::lastOwner();
        }
    }
}
