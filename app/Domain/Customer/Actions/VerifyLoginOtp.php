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
 * Check a sign-in code and open a customer session (P4-STO-11).
 *
 * The code is checked and used up by {@see ConsumeOtp} (a guess is never free; every failure is
 * the same `code` error). A match finds or creates the customer by E.164 phone (the identity
 * guest checkout already uses, rule 30), marks the phone verified and issues a Sanctum token with
 * the `customer` ability only. The customer may have no password yet: the storefront then sends
 * them to "Set your password" (owner decision 2026-11-20) — `AccountResource.has_password`.
 */
final readonly class VerifyLoginOtp
{
    public const string TOKEN_NAME = CustomerSessions::TOKEN_NAME;

    /** @var list<string> */
    public const array ABILITIES = CustomerSessions::ABILITIES;

    public const int SESSION_DAYS = CustomerSessions::SESSION_DAYS;

    public function __construct(private ConsumeOtp $consume, private CustomerSessions $sessions) {}

    public function handle(PhoneNumber $phone, string $code, string $locale): CustomerSession
    {
        $this->consume->handle($phone, $code, OtpCodes::PURPOSE_LOGIN);

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

            return $this->sessions->issue($customer, $isNew);
        });
    }
}
