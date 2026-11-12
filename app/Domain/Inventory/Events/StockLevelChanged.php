<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A variant's stock level changed (on_hand or reserved) — a movement was recorded or a
 * reservation taken/released. Held until the Action's transaction commits, so a
 * rolled-back stock write never reaches a listener (search re-index, storefront
 * revalidation). One event per variant per write.
 */
final readonly class StockLevelChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $variantId,
        public int $productId,
    ) {}
}
