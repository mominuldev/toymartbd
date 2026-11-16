<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Services\TwoFactor;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Saves Staff & roles › Two-factor sign-in (owner request 2026-11-16): whether staff who can see
 * money must set up two-factor before using the admin ({@see TwoFactor::SETTING}, default off).
 *
 * A change writes one `credential_changed` audit row (scope `two_factor_policy`) with the
 * before and after, filed under the actor's account so the Activity log shows it under Staff.
 * Saving the value it already has writes nothing.
 */
final readonly class UpdateTwoFactorRequirement
{
    public function handle(bool $required, ?int $actorId): void
    {
        DB::transaction(function () use ($required, $actorId): void {
            $before = TwoFactor::requiredByOwner();
            $saved = Setting::query()->lockForUpdate()->find(TwoFactor::SETTING) !== null;
            if ($saved && $before === $required) {
                return;
            }

            Setting::put(TwoFactor::SETTING, $required, SettingGroup::System, false, $actorId);

            if ($before === $required) {
                return;
            }

            AuditSensitive::query()->create([
                'user_id' => $actorId,
                'action' => AuditAction::CredentialChanged,
                'model_type' => (new User)->getMorphClass(),
                'model_id' => $actorId,
                'before' => ['scope' => 'two_factor_policy', 'required' => $before],
                'after' => ['scope' => 'two_factor_policy', 'required' => $required],
            ]);
        });
    }
}
