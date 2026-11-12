<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront\Account;

use App\Domain\Customer\Actions\VerifyLoginOtp;
use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Contracts\SmsGateway;

/** Shared fixtures for the customer account tests (P4-STO-11). */
final class AccountTestKit
{
    /** Binds a recording SMS gateway and returns it. */
    public static function fakeSms(): FakeSms
    {
        $fake = new FakeSms;
        app()->instance(SmsGateway::class, $fake);

        return $fake;
    }

    /** A customer's bearer token, as VerifyLoginOtp issues it. */
    public static function tokenFor(Customer $customer): string
    {
        return $customer->createToken(VerifyLoginOtp::TOKEN_NAME, VerifyLoginOtp::ABILITIES, now()->addDays(1))->plainTextToken;
    }
}
