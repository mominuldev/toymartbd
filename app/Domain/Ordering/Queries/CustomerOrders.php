<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Queries;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * A signed-in customer's own orders (P4-STO-11) — read only. Every lookup is scoped by
 * `customer_id`, so another customer's order number simply is not found: the storefront
 * gets the same 404 as for a number that never existed.
 */
final class CustomerOrders
{
    /** Orders still on their way to the customer. */
    public const array ACTIVE = [
        OrderStatus::Pending, OrderStatus::OnHold, OrderStatus::Confirmed, OrderStatus::Processing,
        OrderStatus::ReadyToShip, OrderStatus::Shipped,
    ];

    /**
     * The "My orders" tabs (Figma 239:12108): each customer-facing group and its statuses.
     *
     * @var array<string, list<OrderStatus>>
     */
    public const array GROUPS = [
        'active' => self::ACTIVE,
        'delivered' => [OrderStatus::Delivered, OrderStatus::Completed],
        'cancelled' => [OrderStatus::Cancelled],
        'returned' => [OrderStatus::Returned, OrderStatus::PartiallyReturned],
    ];

    /** @return CursorPaginator<int, Order> */
    public function paginate(int $customerId, int $perPage, ?string $cursor, ?string $group = null): CursorPaginator
    {
        return Order::query()
            ->where('customer_id', $customerId)
            ->when($group !== null, fn ($q) => $q->whereIn('status', self::values(self::GROUPS[$group] ?? [])))
            ->with('items')
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
    }

    public function find(int $customerId, string $number): ?Order
    {
        return Order::query()->where('customer_id', $customerId)->where('number', strtoupper($number))->first();
    }

    /** The newest order still on its way, for the account home. */
    public function latestActive(int $customerId): ?Order
    {
        return Order::query()->where('customer_id', $customerId)
            ->whereIn('status', self::values(self::ACTIVE))
            ->with('items')
            ->orderByDesc('id')
            ->first();
    }

    /** @return array<string, int> orders per group, for the tab counts */
    public function groupCounts(int $customerId): array
    {
        $byStatus = Order::query()->where('customer_id', $customerId)
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        $counts = [];
        foreach (self::GROUPS as $group => $statuses) {
            $counts[$group] = (int) collect(self::values($statuses))->sum(fn (string $status): int => (int) ($byStatus[$status] ?? 0));
        }

        return $counts;
    }

    /**
     * @param  list<OrderStatus>  $statuses
     * @return list<string>
     */
    private static function values(array $statuses): array
    {
        return array_map(static fn (OrderStatus $s): string => $s->value, $statuses);
    }
}
