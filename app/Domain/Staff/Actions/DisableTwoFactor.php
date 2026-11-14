<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Exceptions\StaffConflict;
use App\Domain\Staff\Services\TwoFactor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns your own two-factor off, proven with a current code or a recovery code. Refused
 * while your role can see money (it is required). Audited.
 */
final readonly class DisableTwoFactor
{
    public function __construct(private TwoFactor $twoFactor, private VerifySecondFactor $verify) {}

    public function handle(User $user, ?string $code, ?string $recoveryCode): void
    {
        if ($this->twoFactor->requiredFor($user)) {
            throw StaffConflict::twoFactorRequired();
        }
        if (! $this->verify->handle($user, $code, $recoveryCode)) {
            throw ValidationException::withMessages([($code !== null && $code !== '') ? 'code' : 'recovery_code' => __('staff.two_factor_invalid')]);
        }

        DB::transaction(function () use ($user): void {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            self::clear($locked);
            TwoFactorAudit::record($locked, (int) $locked->getKey(), 'disabled');
        });
    }

    public static function clear(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();
    }
}
