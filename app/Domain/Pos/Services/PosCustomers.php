<?php

declare(strict_types=1);

namespace App\Domain\Pos\Services;

use App\Domain\Customer\Enums\CustomerSource;
use App\Domain\Customer\Models\Customer;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Who a counter sale belongs to. With a phone (rule 30) it is the same customer checkout
 * would find — create-or-find by E.164, never a second row — created as `walk_in`. Without
 * one it is the shop's single anonymous "Walk-in customer" row, because `orders.customer_id`
 * is required.
 *
 * The anonymous row's phone is `+8801000000000`: an 010 number, which the phone rule
 * rejects, so it can never be a real customer's number, never receives a message
 * (Notification parses the phone and skips it) and never matches a real phone lookup.
 *
 * Owns no transaction (rule 3); ChargeSale calls it inside its own. Same no-gap-lock
 * pattern as Ordering's CheckoutCustomers (P9 LOAD-AND-CONCURRENCY F-6).
 */
final class PosCustomers
{
    public const string WALK_IN_PHONE = '+8801000000000';

    public function find(PhoneNumber $phone): ?Customer
    {
        return Customer::query()->withTrashed()->where('phone', $phone->e164())->first();
    }

    public function forSale(?PhoneNumber $phone, ?string $name): Customer
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('PosCustomers runs inside the sale transaction.');
        }

        $e164 = $phone?->e164() ?? self::WALK_IN_PHONE;
        $label = $name !== null && trim($name) !== '' ? trim($name) : (string) __('pos.walk_in_customer', [], 'en');

        if (! Customer::query()->withTrashed()->where('phone', $e164)->exists()) {
            try {
                Customer::query()->withTrashed()->createOrFirst(['phone' => $e164], [
                    'name' => $label,
                    'preferred_language' => app()->getLocale() === 'en' ? 'en' : 'bn',
                    'source' => CustomerSource::WalkIn,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent sale created it after our snapshot: the locking read sees it.
            }
        }

        $customer = Customer::query()->withTrashed()->where('phone', $e164)->lockForUpdate()->firstOrFail();
        if ($customer->trashed()) {
            $customer->restore();
        }

        return $customer;
    }

    public static function isWalkIn(Customer $customer): bool
    {
        return $customer->phone === self::WALK_IN_PHONE;
    }
}
