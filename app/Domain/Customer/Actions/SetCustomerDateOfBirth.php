<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Models\Customer;
use InvalidArgumentException;

/**
 * Staff record (or clear) a customer's date of birth, as the customer told them (P8 birthday
 * messages). Personal data: the value is never logged (rule 20). Changing it never touches
 * the birthday-message consent — that is a separate, explicit record.
 */
final readonly class SetCustomerDateOfBirth
{
    /** @param  string|null  $dateOfBirth  Y-m-d, or null to clear it. */
    public function handle(Customer $customer, ?string $dateOfBirth): Customer
    {
        if ($dateOfBirth !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth) !== 1) {
            throw new InvalidArgumentException('A date of birth is a Y-m-d date.');
        }

        $customer->fill(['date_of_birth' => $dateOfBirth]);
        $customer->save();

        return $customer;
    }
}
