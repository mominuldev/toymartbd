<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** `orders.fulfillment_status` moved (unfulfilled → fulfilled…). */
final readonly class OrderFulfillmentStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $orderId,
        public string $from,
        public string $to,
    ) {}
}
