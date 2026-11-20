<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Models\CustomerOtp;
use App\Domain\Customer\Services\OtpCodes;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Check and use up a one-time code for one purpose (P4-STO-11; register and password reset since
 * 2026-11-20). The newest open code for the phone and purpose is locked and its attempt counter
 * raised BEFORE the comparison, and that increment commits even when the code is wrong — a guess
 * is never free. The fifth wrong guess burns the code; a match consumes it (single use).
 *
 * Every failure is the same `code` validation error, so the answer never says whether the phone
 * has a code at all. Callers run this before (never inside) their own transaction, so a wrong
 * guess is recorded even though the caller's work is refused.
 */
final readonly class ConsumeOtp
{
    public function __construct(private OtpCodes $codes) {}

    public function handle(PhoneNumber $phone, string $code, string $purpose): void
    {
        $phoneHash = $this->codes->phoneHash($phone);

        $outcome = DB::transaction(function () use ($phone, $phoneHash, $code, $purpose): string {
            $otp = CustomerOtp::query()
                ->where('phone_hash', $phoneHash)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', Carbon::now())
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($otp === null) {
                return 'invalid';
            }

            $otp->attempts++;
            $matches = $this->codes->matches($phone, $code, $otp->code_hash);
            if ($matches || $otp->attempts >= OtpCodes::MAX_ATTEMPTS) {
                $otp->consumed_at = Carbon::now();
            }
            $otp->save();

            return $matches ? 'ok' : ($otp->consumed_at !== null ? 'burned' : 'invalid');
        });

        if ($outcome !== 'ok') {
            throw ValidationException::withMessages([
                'code' => (string) __($outcome === 'burned' ? 'account.otp.burned' : 'account.otp.invalid'),
            ]);
        }
    }
}
