<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Queries;

use App\Domain\Marketing\Contracts\CustomerOrderHistory;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Support\PhoneNumber;

/**
 * Answers Marketing's `first_order_only` question from real orders: a phone has
 * ordered before when it owns, or shipped to, any order that was not cancelled.
 */
final class PhoneOrderHistory implements CustomerOrderHistory
{
    public function hasOrdered(PhoneNumber $phone): bool
    {
        $e164 = $phone->e164();

        return Order::query()
            ->where('status', '!=', OrderStatus::Cancelled)
            ->where(function ($query) use ($e164): void {
                $query->where('shipping_phone', $e164)
                    ->orWhereHas('customer', fn ($customer) => $customer->where('phone', $e164));
            })
            ->exists();
    }
}
