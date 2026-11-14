<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Services\TwoFactor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** New recovery codes for your own account, proven with a current code; the old ones stop working. */
final readonly class RegenerateRecoveryCodes
{
    public function __construct(private TwoFactor $twoFactor, private VerifySecondFactor $verify) {}

    /** @return list<string> */
    public function handle(User $user, string $code): array
    {
        if (! $this->verify->handle($user, $code, null)) {
            throw ValidationException::withMessages(['code' => __('staff.two_factor_invalid')]);
        }

        return DB::transaction(function () use ($user): array {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $codes = $this->twoFactor->newRecoveryCodes();
            $locked->forceFill(['two_factor_recovery_codes' => $codes['hashes']])->save();
            TwoFactorAudit::record($locked, (int) $locked->getKey(), 'recovery_codes_regenerated');

            return $codes['plain'];
        });
    }
}
