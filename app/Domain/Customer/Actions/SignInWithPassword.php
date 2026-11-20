<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\DTOs\CustomerSession;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Exceptions\CustomerBlocked;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\CustomerSessions;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Phone + password sign-in (owner decision 2026-11-20). One generic `password` error for an
 * unknown phone, a customer without a password and a wrong password alike — and the unknown cases
 * still run a hash check, so the timing does not tell them apart either. A blocked customer is told
 * only after the right password (as with the SMS code). Guessing is limited per phone and per IP by
 * the `password-login` route limiter.
 */
final readonly class SignInWithPassword
{
    public function __construct(private CustomerSessions $sessions) {}

    public function handle(PhoneNumber $phone, string $password): CustomerSession
    {
        $customer = Customer::query()->where('phone', $phone->e164())->first();

        if ($customer === null || ! $customer->hasPassword()) {
            Hash::check($password, self::dummyHash()); // same work as a real check: the timing tells nothing
            throw self::invalid();
        }
        if (! Hash::check($password, (string) $customer->password)) {
            throw self::invalid();
        }
        if ($customer->status === CustomerStatus::Blocked) {
            throw new CustomerBlocked;
        }

        return $this->sessions->issue($customer, false);
    }

    private static function invalid(): ValidationException
    {
        return ValidationException::withMessages(['password' => (string) __('account.password.invalid')]);
    }

    /**
     * A hash of a random string, made once per process with the configured hasher and cost, so
     * checking it takes as long as checking a real customer's password.
     */
    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(bin2hex(random_bytes(16)));
    }
}
