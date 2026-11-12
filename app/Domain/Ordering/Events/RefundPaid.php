<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A refund was paid out (gateway confirmed, or staff recorded the bKash / Nagad / cash /
 * bank payout). Finance books the cash leaving (rule 10). Fired once: a second "pay" on a
 * completed refund is a no-op and fires nothing. Held until commit.
 */
final readonly class RefundPaid implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $refundId,
        public int $orderId,
        public string $amount,
        public string $method,
        public string $occurredOn,
        public ?int $userId = null,
    ) {}
}
