<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Sets a new temporary password for another staff member (they forgot it, or it may have
 * leaked — RUNBOOK §9). Their API tokens are revoked. Audited as `credential_changed`
 * with the word "changed" only, never the value. Your own password is not changed here.
 */
final readonly class ResetStaffPassword
{
    public function __construct(private StaffGuard $guard) {}

    public function handle(User $staff, #[SensitiveParameter] string $password, ?int $actorId): void
    {
        DB::transaction(function () use ($staff, $password, $actorId): void {
            $this->guard->notSelf($staff, $actorId);

            $staff->forceFill(['password' => $password, 'remember_token' => null])->save();
            $staff->tokens()->delete();

            AuditSensitive::query()->create([
                'user_id' => $actorId,
                'action' => AuditAction::CredentialChanged,
                'model_type' => $staff->getMorphClass(),
                'model_id' => $staff->getKey(),
                'before' => ['scope' => 'staff_password'],
                'after' => ['scope' => 'staff_password', 'password' => 'changed'],
            ]);
        });
    }
}
