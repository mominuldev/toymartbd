<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\DTOs\CustomerSession;
use App\Domain\Customer\Enums\CustomerSource;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Exceptions\CustomerBlocked;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerOtp;
use App\Domain\Customer\Services\OtpCodes;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Check a sign-in code and open a customer session (P4-STO-11).
 *
 * The newest open code for the phone is locked and its attempt counter raised BEFORE the
 * comparison, and that increment commits even when the code is wrong — a guess is never
 * free. The fifth wrong guess burns the code. A match consumes it (single use), finds or
 * creates the customer by E.164 phone (the identity guest checkout already uses, rule 30),
 * marks the phone verified and issues a Sanctum token with the `customer` ability only.
 *
 * Every failure is the same `code` validation error, so the answer never says whether
 * the phone has an account or a code at all.
 */
final readonly class VerifyLoginOtp
{
    public const string TOKEN_NAME = 'storefront-account';

    /** @var list<string> */
    public const array ABILITIES = ['customer'];

    public const int SESSION_DAYS = 60;

    public function __construct(private OtpCodes $codes) {}

    public function handle(PhoneNumber $phone, string $code, string $locale): CustomerSession
    {
        $phoneHash = $this->codes->phoneHash($phone);

        $outcome = DB::transaction(function () use ($phone, $phoneHash, $code): string {
            $otp = CustomerOtp::query()
                ->where('phone_hash', $phoneHash)
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

        return DB::transaction(function () use ($phone, $locale): CustomerSession {
            $customer = Customer::query()->withTrashed()->where('phone', $phone->e164())->lockForUpdate()->first();
            $isNew = $customer === null;
            $customer ??= Customer::query()->withTrashed()->createOrFirst(['phone' => $phone->e164()], [
                'name' => '',
                'preferred_language' => $locale === 'en' ? 'en' : 'bn',
                'source' => CustomerSource::Web,
            ]);

            if ($customer->status === CustomerStatus::Blocked) {
                throw new CustomerBlocked;
            }
            if ($customer->trashed()) {
                $customer->restore();
            }
            $customer->phone_verified_at ??= Carbon::now();
            $customer->save();

            $expiresAt = Carbon::now()->addDays(self::SESSION_DAYS);
            $token = $customer->createToken(self::TOKEN_NAME, self::ABILITIES, $expiresAt);

            return new CustomerSession($customer, $token->plainTextToken, $expiresAt, $isNew);
        });
    }
}
