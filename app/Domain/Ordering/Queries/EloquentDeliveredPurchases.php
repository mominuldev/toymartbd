<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Queries;

use App\Domain\Catalog\Reviews\Contracts\DeliveredPurchases;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ordering's answer to the review "verified purchase" question (P4-CAT-12): a line of
 * the customer's own order for the product, on an order that was delivered
 * (delivered, completed or partially returned), and not returned in full.
 */
final class EloquentDeliveredPurchases implements DeliveredPurchases
{
    public function isDelivered(int $orderItemId, int $customerId, int $productId): bool
    {
        return $this->lines($customerId, $productId)->whereKey($orderItemId)->exists();
    }

    public function deliveredLines(int $customerId, int $productId): array
    {
        return array_values(array_map(intval(...), $this->lines($customerId, $productId)->orderBy('order_items.id')->pluck('order_items.id')->all()));
    }

    /** @return Builder<OrderItem> */
    private function lines(int $customerId, int $productId): Builder
    {
        return OrderItem::query()
            ->where('order_items.product_id', $productId)
            ->whereColumn('order_items.quantity_returned', '<', 'order_items.quantity')
            ->whereHas('order', static fn (Builder $order) => $order
                ->where('customer_id', $customerId)
                ->whereIn('status', array_map(static fn (OrderStatus $s): string => $s->value, EloquentCustomerOrderHistory::DELIVERED)));
    }
}
