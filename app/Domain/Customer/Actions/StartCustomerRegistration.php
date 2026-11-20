<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\DTOs\OtpIssued;
use App\Domain\Customer\Exceptions\PhoneAlreadyRegistered;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\OtpCodes;
use App\Support\PhoneNumber;

/**
 * "Create an account", step 1 (owner decision 2026-11-20): refuse a phone that is already
 * registered ({@see Customer::isRegistered()} — a guest-checkout row is not), otherwise text a
 * `register` code. The 409 tells a caller the phone has an account; the owner accepted that
 * trade-off, and the route limits it per phone and per IP like any code request.
 */
final readonly class StartCustomerRegistration
{
    public function __construct(private RequestLoginOtp $requestOtp) {}

    public function handle(PhoneNumber $phone, ?string $ip, string $locale): OtpIssued
    {
        $existing = Customer::query()->where('phone', $phone->e164())->first();
        if ($existing !== null && $existing->isRegistered()) {
            throw new PhoneAlreadyRegistered;
        }

        return $this->requestOtp->handle($phone, $ip, $locale, OtpCodes::PURPOSE_REGISTER);
    }
}
