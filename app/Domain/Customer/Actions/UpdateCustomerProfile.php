<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Models\Customer;

/**
 * The customer edits their own details (P4-STO-11). The phone is the identity and is not
 * editable here: changing it would need an OTP to the new number. (Birthday reminders are about
 * the customer's children, P8 batch 3 — see SaveCustomerChild; the customer's own date of birth
 * is no longer collected.)
 */
final readonly class UpdateCustomerProfile
{
    /**
     * @param  array{name?: string, email?: string|null, preferred_language?: string}  $changes
     */
    public function handle(Customer $customer, array $changes): Customer
    {
        if (array_key_exists('email', $changes) && $changes['email'] !== $customer->email) {
            $customer->email_verified_at = null;
        }
        $customer->fill($changes);
        $customer->save();

        return $customer;
    }
}
