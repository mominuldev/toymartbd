<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\DTOs\CustomerSession;
use App\Domain\Customer\Enums\CustomerSource;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Exceptions\CustomerBlocked;
use App\Domain\Customer\Exceptions\PhoneAlreadyRegistered;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\CustomerSessions;
use App\Domain\Customer\Services\OtpCodes;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Create an account", step 2 (owner decision 2026-11-20): the `register` code proves the phone,
 * then the account is made with the name and password and signed in.
 *
 * A guest-checkout row for the phone becomes the account (its orders stay with it); its name is
 * kept when it already has one — never overwritten. A phone that became registered since step 1
 * is refused (409) under the row lock, so two racing sign-ups cannot both set a password.
 */
final readonly class RegisterCustomer
{
    public function __construct(private ConsumeOtp $consume, private CustomerSessions $sessions) {}

    public function handle(PhoneNumber $phone, string $code, string $name, string $password, string $locale): CustomerSession
    {
        $this->consume->handle($phone, $code, OtpCodes::PURPOSE_REGISTER);

        return DB::transaction(function () use ($phone, $name, $password, $locale): CustomerSession {
            $customer = Customer::query()->withTrashed()->where('phone', $phone->e164())->lockForUpdate()->first();
            if ($customer !== null && ! $customer->trashed() && $customer->isRegistered()) {
                throw new PhoneAlreadyRegistered;
            }

            $isNew = $customer === null;
            $customer ??= Customer::query()->withTrashed()->createOrFirst(['phone' => $phone->e164()], [
                'name' => $name,
                'preferred_language' => $locale === 'en' ? 'en' : 'bn',
                'source' => CustomerSource::Web,
            ]);

            if ($customer->status === CustomerStatus::Blocked) {
                throw new CustomerBlocked;
            }
            if ($customer->trashed()) {
                $customer->restore();
            }
            if (trim($customer->name) === '') {
                $customer->name = $name;
            }
            $customer->password = $password;
            $customer->password_set_at = Carbon::now();
            $customer->phone_verified_at ??= Carbon::now();
            $customer->save();

            return $this->sessions->issue($customer, $isNew);
        });
    }
}
