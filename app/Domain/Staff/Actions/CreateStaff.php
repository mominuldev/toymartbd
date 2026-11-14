<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\DTOs\NewStaffData;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds a staff account with exactly one role (Admin → Staff & roles → Invite staff).
 * The role grant is audited (`role_assigned`); the password never is.
 */
final readonly class CreateStaff
{
    public function handle(NewStaffData $data, ?int $actorId): User
    {
        return DB::transaction(function () use ($data, $actorId): User {
            $user = User::query()->create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
                'is_active' => true,
            ]);
            $user->syncRoles([$data->role->value]);

            AuditSensitive::query()->create([
                'user_id' => $actorId,
                'action' => AuditAction::RoleAssigned,
                'model_type' => $user->getMorphClass(),
                'model_id' => $user->getKey(),
                'before' => ['roles' => []],
                'after' => ['roles' => [$data->role->value], 'created' => true],
            ]);

            return $user->refresh()->load('roles');
        });
    }
}
