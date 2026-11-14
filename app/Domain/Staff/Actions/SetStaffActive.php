<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates or reactivates a staff account. Staff are never deleted: orders and the
 * audit log keep their name. A deactivated account cannot sign in, its API tokens are
 * revoked, and its open admin session is refused on the next request
 * (EnsureActiveStaffSession). Audited as `role_revoked` / `role_assigned`.
 */
final readonly class SetStaffActive
{
    public function __construct(private StaffGuard $guard) {}

    public function handle(User $staff, bool $active, ?int $actorId): User
    {
        return DB::transaction(function () use ($staff, $active, $actorId): User {
            if ($staff->is_active === $active) {
                return $staff->load('roles');
            }

            $this->guard->notSelf($staff, $actorId);
            if (! $active) {
                $this->guard->keepsAnOwner($staff);
            }

            $staff->forceFill(['is_active' => $active])->save();
            if (! $active) {
                $staff->tokens()->delete();
            }

            AuditSensitive::query()->create([
                'user_id' => $actorId,
                'action' => $active ? AuditAction::RoleAssigned : AuditAction::RoleRevoked,
                'model_type' => $staff->getMorphClass(),
                'model_id' => $staff->getKey(),
                'before' => ['is_active' => ! $active],
                'after' => ['is_active' => $active, 'roles' => $staff->getRoleNames()->values()->all()],
            ]);

            return $staff->refresh()->load('roles');
        });
    }
}
