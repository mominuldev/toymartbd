<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Queries;

use App\Domain\Customer\Contracts\CustomerOrderHistory;
use App\Domain\Customer\DTOs\CustomerOrderStats;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Ordering's answer to the Customer projection (P4-ORD-10). Definitions are documented on
 * {@see CustomerOrderStats}. Money is summed in PHP with bcmath, not SQL SUM — SQLite sums
 * DECIMAL as float, and the projection must match a recomputation to the poisha.
 */
final class EloquentCustomerOrderHistory implements CustomerOrderHistory
{
    public const array DELIVERED = [OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::PartiallyReturned];

    public function statsFor(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        /** @var array<int, array{orders: int, delivered: int, spent: Money, first: ?CarbonImmutable, last: ?CarbonImmutable}> $acc */
        $acc = [];

        Order::query()
            ->whereIn('customer_id', $customerIds)
            ->select(['id', 'customer_id', 'status', 'grand_total', 'refunded_total', 'placed_at', 'created_at'])
            ->orderBy('id')
            ->each(function (Order $order) use (&$acc): void {
                $row = $acc[$order->customer_id] ?? ['orders' => 0, 'delivered' => 0, 'spent' => Money::zero(), 'first' => null, 'last' => null];
                $at = CarbonImmutable::instance($order->placed_at ?? $order->created_at ?? now());

                if ($order->status !== OrderStatus::Cancelled) {
                    $row['orders']++;
                }
                if (in_array($order->status, self::DELIVERED, true)) {
                    $row['delivered']++;
                    $row['spent'] = $row['spent']->plus($order->grand_total->minus($order->refunded_total));
                }
                $row['first'] = $row['first'] === null || $at->lessThan($row['first']) ? $at : $row['first'];
                $row['last'] = $row['last'] === null || $at->greaterThan($row['last']) ? $at : $row['last'];

                $acc[$order->customer_id] = $row;
            });

        return array_map(
            static fn (array $r): CustomerOrderStats => new CustomerOrderStats($r['orders'], $r['delivered'], $r['spent'], $r['first'], $r['last']),
            $acc,
        );
    }
}
