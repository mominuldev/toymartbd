<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order was created (PlaceOrder, batch 5; manual orders, P4-ORD-06). After commit:
 * notifications, order_financials, customer aggregates, search re-index (§4.1).
 */
final readonly class OrderPlaced implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $orderId,
        public int $customerId,
        public ?string $couponCode = null,
    ) {}
}
