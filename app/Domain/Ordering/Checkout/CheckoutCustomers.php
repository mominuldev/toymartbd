<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Checkout;

use App\Domain\Customer\Enums\AddressType;
use App\Domain\Customer\Enums\CustomerSource;
use App\Domain\Customer\Models\Address;
use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Exceptions\OrderViolation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Checkout's customer and address step (DATABASE-DESIGN §4.1, orders.md step 4): the
 * customer is found by E.164 phone — the key that survives guest checkouts (rule 30) —
 * or created; the address (division → district → upazila → area + line + landmark,
 * rule 31) is reused when identical, otherwise added to the customer's book.
 *
 * Owns no transaction (rule 3); PlaceOrder calls it inside its own. Never overwrites a
 * returning customer's name: the order keeps its own snapshot.
 */
final class CheckoutCustomers
{
    /** The phone's customer, if any, without creating one (pricing needs the id first). */
    public function find(CheckoutData $data): ?Customer
    {
        return Customer::query()->withTrashed()->where('phone', $data->phone->e164())->first();
    }

    public function findOrCreate(CheckoutData $data): Customer
    {
        self::guard();

        $phone = $data->phone->e164();

        // Never lock a phone that has no row yet (P9, LOAD-AND-CONCURRENCY F-6): a locking
        // read of a missing key takes a GAP lock on customers_phone_unique, and two first
        // orders from DIFFERENT new phones in the same gap then deadlock on their inserts —
        // every pair of new customers in a launch-day burst (found by the MySQL load run).
        // So: a plain read; a missing phone is inserted (insert-intention locks do not
        // conflict with each other; createOrFirst's savepoint absorbs a same-phone race);
        // only then is the now-existing row locked, which takes a record lock, not a gap.
        if (! Customer::query()->withTrashed()->where('phone', $phone)->exists()) {
            try {
                Customer::query()->withTrashed()->createOrFirst(['phone' => $phone], [
                    'name' => $data->name,
                    'email' => $data->email,
                    'preferred_language' => $data->locale === 'en' ? 'en' : 'bn',
                    'source' => CustomerSource::tryFrom($data->source->value) ?? CustomerSource::Web,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Committed by a concurrent checkout after this transaction's snapshot: the
                // locking read below sees it.
            }
        }
        $customer = Customer::query()->withTrashed()->where('phone', $phone)->lockForUpdate()->firstOrFail();

        if ($customer->trashed()) {
            // A deleted customer ordering again is a returning customer.
            $customer->restore();
        }
        if ($customer->email === null && $data->email !== null) {
            $customer->email = $data->email;
            $customer->save();
        }

        return $customer;
    }

    public function address(Customer $customer, CheckoutData $data): Address
    {
        self::guard();

        $attributes = [
            'customer_id' => $customer->id,
            'recipient_name' => $data->name,
            'phone' => $data->phone->e164(),
            'division_id' => $data->district->division_id,
            'district_id' => $data->district->id,
            'upazila_id' => $data->upazila?->id,
            'area' => $data->area,
            'address_line' => $data->addressLine,
            'landmark' => $data->landmark,
        ];

        $existing = Address::query();
        foreach ($attributes as $column => $value) {
            $value === null ? $existing->whereNull($column) : $existing->where($column, $value);
        }
        $address = $existing->orderBy('id')->first();
        if ($address !== null) {
            return $address;
        }

        $first = ! Address::query()->where('customer_id', $customer->id)->exists();

        return Address::query()->create($attributes + [
            'alt_phone' => $data->altPhone?->e164(),
            'postcode' => $data->postcode,
            'type' => AddressType::Home,
            'is_default_shipping' => $first,
            'is_default_billing' => $first,
        ]);
    }

    private static function guard(): void
    {
        if (DB::transactionLevel() === 0) {
            throw OrderViolation::outsideTransaction();
        }
    }
}
