<?php

declare(strict_types=1);

namespace App\Domain\Customer\DTOs;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One customer's order history, as Ordering reports it (P4-ORD-10). Definitions — kept in
 * one place, {@see \App\Domain\Ordering\Queries\EloquentCustomerOrderHistory}:
 *
 *  - ordersCount: orders placed, excluding cancelled ones (a cancellation before dispatch
 *    never became a sale);
 *  - deliveredOrdersCount: orders that reached the customer (delivered, completed,
 *    partially_returned);
 *  - totalSpent: Σ (grand_total − refunded_total) over those delivered orders — revenue is
 *    recognised on delivery (rule 10), so an undelivered COD order is not "spent";
 *  - first/lastOrderAt: placed_at over every order, cancelled included.
 */
final readonly class CustomerOrderStats
{
    public function __construct(
        public int $ordersCount,
        public int $deliveredOrdersCount,
        public Money $totalSpent,
        public ?CarbonImmutable $firstOrderAt,
        public ?CarbonImmutable $lastOrderAt,
    ) {}

    public static function none(): self
    {
        return new self(0, 0, Money::zero(), null, null);
    }

    /** Average over delivered orders, half away from zero to the poisha. */
    public function averageOrderValue(): Money
    {
        if ($this->deliveredOrdersCount === 0) {
            return Money::zero();
        }

        return Money::of(bcdiv($this->totalSpent->toDecimal(), (string) $this->deliveredOrdersCount, 6));
    }
}
