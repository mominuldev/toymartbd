<?php

declare(strict_types=1);

namespace App\Domain\Customer\Services;

use App\Domain\Customer\DTOs\CustomerSession;
use App\Domain\Customer\Models\Customer;
use Illuminate\Support\Carbon;

/**
 * Issues a storefront customer's Sanctum token (P4-STO-11): ability `customer` only, 60 days. The
 * plain token goes back once, to the Next.js server, which keeps it in an httpOnly cookie
 * (rule 28). Every sign-in path — SMS code, password, register, password reset — ends here.
 */
final class CustomerSessions
{
    public const string TOKEN_NAME = 'storefront-account';

    /** @var list<string> */
    public const array ABILITIES = ['customer'];

    public const int SESSION_DAYS = 60;

    public function issue(Customer $customer, bool $isNewCustomer): CustomerSession
    {
        $expiresAt = Carbon::now()->addDays(self::SESSION_DAYS);
        $token = $customer->createToken(self::TOKEN_NAME, self::ABILITIES, $expiresAt);

        return new CustomerSession($customer, $token->plainTextToken, $expiresAt, $isNewCustomer);
    }
}
