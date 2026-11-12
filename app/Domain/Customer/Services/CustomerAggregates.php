<?php

declare(strict_types=1);

namespace App\Domain\Customer\Services;

use App\Domain\Customer\Contracts\CustomerOrderHistory;
use App\Domain\Customer\DTOs\CustomerOrderStats;
use App\Domain\Customer\Models\Customer;

/**
 * The customer aggregate projection (P4-ORD-10, DATABASE-DESIGN §3.3): the only writer of
 * `orders_count`, `delivered_orders_count`, `total_spent`, `avg_order_value`,
 * `first_order_at` and `last_order_at`. Always a full recomputation from orders — never an
 * increment — so applying it twice, or out of order, converges on the same answer.
 */
final readonly class CustomerAggregates
{
    public function __construct(private CustomerOrderHistory $history) {}

    /**
     * Recompute and write the aggregates for these customers.
     *
     * @param  list<int>  $customerIds
     * @return int customers written
     */
    public function refresh(array $customerIds): int
    {
        if ($customerIds === []) {
            return 0;
        }

        $stats = $this->history->statsFor($customerIds);
        $written = 0;

        Customer::withTrashed()->whereKey($customerIds)->orderBy('id')->each(function (Customer $customer) use ($stats, &$written): void {
            $this->write($customer, $stats[$customer->id] ?? CustomerOrderStats::none());
            $written++;
        });

        return $written;
    }

    private function write(Customer $customer, CustomerOrderStats $stats): void
    {
        $customer->forceFill([
            'orders_count' => $stats->ordersCount,
            'delivered_orders_count' => $stats->deliveredOrdersCount,
            'total_spent' => $stats->totalSpent,
            'avg_order_value' => $stats->averageOrderValue(),
            'first_order_at' => $stats->firstOrderAt,
            'last_order_at' => $stats->lastOrderAt,
            'aggregates_refreshed_at' => now(),
        ])->saveQuietly();
    }
}
