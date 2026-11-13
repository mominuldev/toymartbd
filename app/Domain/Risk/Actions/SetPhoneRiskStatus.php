<?php

declare(strict_types=1);

namespace App\Domain\Risk\Actions;

use App\Domain\Risk\Enums\RiskProfileStatus;
use App\Domain\Risk\Models\CustomerRiskProfile;
use Illuminate\Support\Facades\DB;

/**
 * Staff put a phone on the watchlist / blacklist / whitelist, or back to normal — always with
 * a reason, and who and when (§3.3). Scoring never calls this. A blacklisted phone's orders
 * are HELD for a person to look at, never cancelled (rules 23, 24b). Creates the profile if
 * the phone has none yet (blacklisting a number before it ever orders).
 */
final readonly class SetPhoneRiskStatus
{
    public function __construct(private RefreshRiskProfile $refresh) {}

    public function handle(string $e164, RiskProfileStatus $status, string $reason, ?int $userId): CustomerRiskProfile
    {
        return DB::transaction(function () use ($e164, $status, $reason, $userId): CustomerRiskProfile {
            $profile = CustomerRiskProfile::query()->where('phone', $e164)->lockForUpdate()->first()
                ?? $this->refresh->handle($e164);

            $profile->forceFill([
                'status' => $status,
                'status_reason' => $reason,
                'status_set_by' => $userId,
                'status_set_at' => now(),
            ])->save();

            return $profile;
        });
    }
}
