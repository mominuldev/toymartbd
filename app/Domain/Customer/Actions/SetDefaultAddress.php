<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Models\Address;
use Illuminate\Support\Facades\DB;

/** Make one saved address the customer's default (exactly one default at a time). */
final readonly class SetDefaultAddress
{
    public function handle(Address $address): Address
    {
        return DB::transaction(function () use ($address): Address {
            Address::query()->where('customer_id', $address->customer_id)->whereKeyNot($address->id)
                ->where('is_default_shipping', true)->update(['is_default_shipping' => false]);
            $address->is_default_shipping = true;
            $address->save();

            return $address;
        });
    }
}
