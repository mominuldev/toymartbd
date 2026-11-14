<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Exceptions\StaffConflict;
use App\Domain\Staff\Services\TwoFactor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns two-factor on once the person proves their app shows the right code. Issues the
 * recovery codes (returned once, stored hashed) and audits `credential_changed`.
 */
final readonly class ConfirmTwoFactor
{
    public function __construct(private TwoFactor $twoFactor) {}

    /** @return list<string> the plain recovery codes */
    public function handle(User $user, string $code): array
    {
        return DB::transaction(function () use ($user, $code): array {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->hasTwoFactor()) {
                throw StaffConflict::twoFactorAlreadyOn();
            }
            if ($locked->two_factor_secret === null) {
                throw StaffConflict::twoFactorNotStarted();
            }

            $step = $this->twoFactor->verify($locked->two_factor_secret, $code, null);
            if ($step === null) {
                throw ValidationException::withMessages(['code' => __('staff.two_factor_invalid')]);
            }

            $codes = $this->twoFactor->newRecoveryCodes();
            $locked->forceFill([
                'two_factor_confirmed_at' => now(),
                'two_factor_last_used_step' => $step,
                'two_factor_recovery_codes' => $codes['hashes'],
            ])->save();

            TwoFactorAudit::record($locked, (int) $locked->getKey(), 'enabled');

            return $codes['plain'];
        });
    }
}
