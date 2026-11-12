<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Models\Address;
use Illuminate\Support\Facades\DB;

/**
 * Remove a saved address. Orders hold their own snapshot, so nothing placed changes.
 * Removing the default promotes the most recently added remaining address.
 */
final readonly class DeleteCustomerAddress
{
    public function handle(Address $address): void
    {
        DB::transaction(function () use ($address): void {
            $wasDefault = $address->is_default_shipping;
            $customerId = $address->customer_id;
            $address->delete();

            if ($wasDefault && $customerId !== null) {
                Address::query()->where('customer_id', $customerId)->orderByDesc('id')->limit(1)
                    ->update(['is_default_shipping' => true]);
            }
        });
    }
}
