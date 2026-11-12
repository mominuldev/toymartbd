<?php

declare(strict_types=1);

namespace App\Domain\Payment\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A provider answer was refused and the payment needs a human (amount/currency mismatch,
 * paid after the attempt closed — rule 19). Held until commit. Ordering listens and puts
 * the order on hold (or notes it, when it can no longer be held).
 */
final readonly class PaymentFlaggedForReview implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $paymentId,
        public int $orderId,
        public string $method,
        public string $reason,
    ) {}
}
