<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Renames a staff member and/or changes their single role. A role change is audited
 * (`role_assigned` with before/after) and refused on yourself or the last active owner.
 */
final readonly class UpdateStaff
{
    public function __construct(private StaffGuard $guard) {}

    public function handle(User $staff, ?string $name, ?Role $role, ?int $actorId): User
    {
        return DB::transaction(function () use ($staff, $name, $role, $actorId): User {
            /** @var list<string> $before */
            $before = $staff->getRoleNames()->values()->all();
            $changesRole = $role !== null && $before !== [$role->value];

            if ($changesRole) {
                $this->guard->notSelf($staff, $actorId);
                if ($role !== Role::SuperAdmin) {
                    $this->guard->keepsAnOwner($staff);
                }
            }

            if ($name !== null && $name !== $staff->name) {
                $staff->forceFill(['name' => $name])->save();
            }

            if ($changesRole) {
                $staff->syncRoles([$role->value]);

                AuditSensitive::query()->create([
                    'user_id' => $actorId,
                    'action' => AuditAction::RoleAssigned,
                    'model_type' => $staff->getMorphClass(),
                    'model_id' => $staff->getKey(),
                    'before' => ['roles' => $before],
                    'after' => ['roles' => [$role->value]],
                ]);
            }

            return $staff->refresh()->load('roles');
        });
    }
}
