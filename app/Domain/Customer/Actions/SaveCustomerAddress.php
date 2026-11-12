<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\DTOs\AddressData;
use App\Domain\Customer\Models\Address;
use App\Domain\Customer\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Add or edit an address in the customer's book (P4-STO-11). The first address becomes
 * the default; making one the default clears the flag on every other, so a customer has
 * at most one default shipping address. Orders keep their own snapshot, so editing an
 * address never changes a placed order.
 */
final readonly class SaveCustomerAddress
{
    public const int MAX_ADDRESSES = 20;

    public function handle(Customer $customer, AddressData $data, ?Address $address = null): Address
    {
        return DB::transaction(function () use ($customer, $data, $address): Address {
            // Serialise address-book writes for this customer.
            Customer::query()->whereKey($customer->id)->lockForUpdate()->value('id');
            $others = Address::query()->where('customer_id', $customer->id)
                ->when($address !== null, fn ($q) => $q->whereKeyNot($address->id));

            if ($address === null && (clone $others)->count() >= self::MAX_ADDRESSES) {
                throw ValidationException::withMessages(['address_line' => (string) __('account.address.limit', ['max' => self::MAX_ADDRESSES])]);
            }

            $address ??= new Address(['customer_id' => $customer->id]);
            // An edit keeps an existing default; the first address is always the default.
            $makeDefault = $data->makeDefault || $address->is_default_shipping === true || ! (clone $others)->exists();

            $address->fill([
                'type' => $data->type,
                'label' => $data->label,
                'recipient_name' => $data->recipientName,
                'phone' => $data->phone->e164(),
                'division_id' => $data->district->division_id,
                'district_id' => $data->district->id,
                'upazila_id' => $data->upazila?->id,
                'area' => $data->area,
                'address_line' => $data->addressLine,
                'landmark' => $data->landmark,
                'is_default_shipping' => $makeDefault,
            ]);
            $address->customer_id = $customer->id;
            $address->save();

            if ($makeDefault) {
                Address::query()->where('customer_id', $customer->id)->whereKeyNot($address->id)
                    ->where('is_default_shipping', true)->update(['is_default_shipping' => false]);
            }

            return $address->refresh();
        });
    }
}
