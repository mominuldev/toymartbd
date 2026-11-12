<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Contracts;

use App\Domain\Marketing\Services\NoCustomerOrderHistory;
use App\Support\PhoneNumber;
use Illuminate\Container\Attributes\Bind;

/**
 * What a `first_order_only` coupon needs to know: has this phone placed an order
 * before? Ordering owns orders (batch 5) and binds the real implementation; until it
 * does, {@see NoCustomerOrderHistory} answers no — which is true while no orders table
 * exists.
 */
#[Bind(NoCustomerOrderHistory::class)]
interface CustomerOrderHistory
{
    public function hasOrdered(PhoneNumber $phone): bool;
}
