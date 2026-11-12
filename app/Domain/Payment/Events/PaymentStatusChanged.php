<?php

declare(strict_types=1);

namespace App\Domain\Payment\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment moved status. Held until commit. Ordering listens and re-derives the order's
 * `payment_status` / `paid_total` (Payment never writes to orders — cross-domain writes
 * go through events, rule 2).
 */
final readonly class PaymentStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $paymentId,
        public int $orderId,
        public string $method,
        public string $from,
        public string $to,
        public string $amount,
    ) {}
}
