<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Exceptions\StaffConflict;
use App\Domain\Staff\Services\TwoFactor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Starts (or restarts) two-factor setup for the signed-in staff member: a new secret,
 * stored encrypted but NOT active until confirmed with a code. Refused while one is active.
 */
final readonly class BeginTwoFactorSetup
{
    public function __construct(private TwoFactor $twoFactor) {}

    /** @return string the new secret, shown once as a QR code and a setup key */
    public function handle(User $user): string
    {
        return DB::transaction(function () use ($user): string {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->hasTwoFactor()) {
                throw StaffConflict::twoFactorAlreadyOn();
            }

            $secret = $this->twoFactor->newSecret();
            $locked->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_used_step' => null,
            ])->save();

            return $secret;
        });
    }
}
