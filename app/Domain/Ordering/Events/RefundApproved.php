<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A refund was approved: the money is owed to the customer. Finance books the `refund`
 * as recognised negative revenue (not cash — no money has moved yet). Held until commit.
 * `amount` is a decimal string; `occurredOn` a Dhaka date (rule 11).
 */
final readonly class RefundApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $refundId,
        public int $orderId,
        public string $amount,
        public string $occurredOn,
        public bool $recognised,
        public ?int $userId = null,
    ) {}
}
