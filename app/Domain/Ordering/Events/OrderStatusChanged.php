<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base of the per-status transition events. Held until the Action's transaction commits,
 * so a rolled-back transition never reaches a listener.
 *
 * @see \App\Domain\Ordering\Services\OrderTransitions::eventFor()
 */
abstract readonly class OrderStatusChanged implements OrderStatusTransition, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $orderId,
        public int $customerId,
        public string $from,
        public string $to,
        public ?int $userId = null,
    ) {}
}
