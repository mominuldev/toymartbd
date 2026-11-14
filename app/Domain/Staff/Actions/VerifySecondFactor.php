<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Services\TwoFactor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Checks a second factor for an active setup: an authenticator code (not one already used)
 * or an unused recovery code, which is then spent and audited. The user row is locked so
 * two requests cannot spend the same code. Returns false on a wrong code; never throws.
 */
final readonly class VerifySecondFactor
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function handle(User $user, ?string $code, ?string $recoveryCode): bool
    {
        return DB::transaction(function () use ($user, $code, $recoveryCode): bool {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->hasTwoFactor() || $locked->two_factor_secret === null) {
                return false;
            }

            if ($code !== null && $code !== '') {
                $step = $this->twoFactor->verify($locked->two_factor_secret, $code, $locked->two_factor_last_used_step);
                if ($step === null) {
                    return false;
                }
                $locked->forceFill(['two_factor_last_used_step' => $step])->save();

                return true;
            }

            if ($recoveryCode !== null && $recoveryCode !== '') {
                $left = $this->twoFactor->spendRecoveryCode($locked->two_factor_recovery_codes ?? [], $recoveryCode);
                if ($left === null) {
                    return false;
                }
                $locked->forceFill(['two_factor_recovery_codes' => $left])->save();
                TwoFactorAudit::record($locked, (int) $locked->getKey(), 'recovery_code_used');

                return true;
            }

            return false;
        });
    }
}
