<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** `orders.payment_status` moved (unpaid → paid, paid → refunded…). */
final readonly class OrderPaymentStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $orderId,
        public int $customerId,
        public string $from,
        public string $to,
    ) {}
}
