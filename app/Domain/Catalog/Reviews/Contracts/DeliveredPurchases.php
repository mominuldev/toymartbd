<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Reviews\Contracts;

/**
 * What reviews need to know about orders (P4-CAT-12): whether an order line is a
 * delivered purchase of a product by a customer. Catalog declares it; Ordering
 * implements it (deptrac: Catalog never reads orders directly).
 *
 * "Delivered" means the order reached `delivered`, `completed` or
 * `partially_returned`, and this line was not returned in full.
 */
interface DeliveredPurchases
{
    /** Whether `$orderItemId` is a delivered line for `$productId` on one of `$customerId`'s own orders. */
    public function isDelivered(int $orderItemId, int $customerId, int $productId): bool;

    /**
     * The customer's delivered lines for the product, oldest first.
     *
     * @return list<int> order_items ids
     */
    public function deliveredLines(int $customerId, int $productId): array;
}
