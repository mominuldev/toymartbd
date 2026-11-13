<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A return was inspected: its goods are back, resellable units restocked and damaged
 * units written off (Inventory movements in the same transaction). Held until commit.
 *
 * Finance reverses COGS for every returned unit at its `cost_price_snapshot` (rule 7) and
 * writes the damaged units off — `returnedCost` and `damagedCost` are those snapshot
 * figures (decimal strings, BDT). `recognised` is false when the order never reached
 * delivery, so no revenue or COGS was ever booked for it (§7.6).
 */
final readonly class ReturnInspected implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $returnId,
        public int $orderId,
        public string $returnedCost,
        public string $damagedCost,
        public string $occurredOn,
        public bool $recognised,
        public ?int $refundId = null,
    ) {}
}
