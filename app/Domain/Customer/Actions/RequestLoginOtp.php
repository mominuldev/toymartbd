<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\DTOs\OtpIssued;
use App\Domain\Customer\Exceptions\OtpCooldown;
use App\Domain\Customer\Jobs\SendLoginOtp;
use App\Domain\Customer\Models\CustomerOtp;
use App\Domain\Customer\Services\OtpCodes;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Send a sign-in code by SMS (P4-STO-11, rule 32b). Works the same for a known and an
 * unknown phone, so the answer never reveals who has an account.
 *
 * Enforces the resend cooldown and the hourly cap per phone (OtpCodes), then supersedes
 * any open code — only the newest code works. The SMS is queued after commit (rule 4).
 */
final readonly class RequestLoginOtp
{
    public function __construct(private OtpCodes $codes) {}

    public function handle(PhoneNumber $phone, ?string $ip, string $locale): OtpIssued
    {
        $phoneHash = $this->codes->phoneHash($phone);
        $code = $this->codes->generate();

        /** @var CustomerOtp $otp */
        $otp = DB::transaction(function () use ($phone, $phoneHash, $code, $ip): CustomerOtp {
            $now = Carbon::now();
            $recent = CustomerOtp::query()
                ->where('phone_hash', $phoneHash)
                ->where('created_at', '>', $now->copy()->subHour())
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get(['id', 'created_at']);

            $last = $recent->first()?->created_at;
            if ($last !== null && $last->diffInSeconds($now, true) < OtpCodes::RESEND_COOLDOWN_SECONDS) {
                throw new OtpCooldown(max(1, OtpCodes::RESEND_COOLDOWN_SECONDS - (int) $last->diffInSeconds($now, true)));
            }
            if ($recent->count() >= OtpCodes::MAX_SENDS_PER_HOUR) {
                $oldest = $recent->last()->created_at ?? $now;

                throw new OtpCooldown(max(1, 3600 - (int) $oldest->diffInSeconds($now, true)));
            }

            // Only the newest code works: close every code still open for this phone.
            CustomerOtp::query()->where('phone_hash', $phoneHash)->whereNull('consumed_at')
                ->where('expires_at', '>', $now)->update(['expires_at' => $now]);

            return CustomerOtp::query()->create([
                'phone_hash' => $phoneHash,
                'code_hash' => $this->codes->codeHash($phone, $code),
                'purpose' => OtpCodes::PURPOSE_LOGIN,
                'expires_at' => $now->copy()->addSeconds(OtpCodes::TTL_SECONDS),
                'ip_hash' => $this->codes->ipHash($ip),
            ]);
        });

        SendLoginOtp::dispatch($phone->e164(), $code, $locale === 'en' ? 'en' : 'bn', $otp->expires_at->getTimestamp())->afterCommit();

        return new OtpIssued(OtpCodes::TTL_SECONDS, OtpCodes::RESEND_COOLDOWN_SECONDS, OtpCodes::LENGTH);
    }
}
