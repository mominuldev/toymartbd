<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\DTOs\CustomerSession;
use App\Domain\Customer\Enums\CustomerSource;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Exceptions\CustomerBlocked;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\CustomerSessions;
use App\Domain\Customer\Services\OtpCodes;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Forgot password" (owner decision 2026-11-20): the `password_reset` code proves the phone, the
 * new password is set, every earlier session is signed out, and the customer is signed in.
 *
 * As with the sign-in code, proving the phone is enough: a phone with no customer yet gets one
 * (name empty — the storefront asks for it), exactly what an SMS sign-in would have done.
 */
final readonly class ResetCustomerPassword
{
    public function __construct(private ConsumeOtp $consume, private CustomerSessions $sessions) {}

    public function handle(PhoneNumber $phone, string $code, string $password, string $locale): CustomerSession
    {
        $this->consume->handle($phone, $code, OtpCodes::PURPOSE_PASSWORD_RESET);

        return DB::transaction(function () use ($phone, $password, $locale): CustomerSession {
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
            $customer->password = $password;
            $customer->password_set_at = Carbon::now();
            $customer->phone_verified_at ??= Carbon::now();
            $customer->save();

            // A reset means the old password may be known to someone else: end every other session.
            $customer->tokens()->delete();

            return $this->sessions->issue($customer, $isNew);
        });
    }
}
