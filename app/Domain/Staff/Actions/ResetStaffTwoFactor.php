<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * An owner clears someone else's two-factor (lost or replaced phone, RUNBOOK §10). Their API
 * tokens are revoked; if their role sees money they must set it up again at next sign-in.
 * Not for your own account. Audited with the owner as the actor.
 */
final readonly class ResetStaffTwoFactor
{
    public function __construct(private StaffGuard $guard) {}

    public function handle(User $staff, ?int $actorId): User
    {
        return DB::transaction(function () use ($staff, $actorId): User {
            $this->guard->notSelf($staff, $actorId);
            $locked = User::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->hasTwoFactor() && $locked->two_factor_secret === null) {
                return $locked->load('roles');
            }

            DisableTwoFactor::clear($locked);
            $locked->tokens()->delete();
            TwoFactorAudit::record($locked, $actorId, 'reset_by_owner');

            return $locked->refresh()->load('roles');
        });
    }
}
