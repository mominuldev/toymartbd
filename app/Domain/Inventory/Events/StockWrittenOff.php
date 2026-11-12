<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Stock left the business with no sale (P5-INV): an applied or approved adjustment for
 * damage, loss, theft or expiry (StockAdjustmentReason::isWriteOff). Finance books
 * `totalCost` as an `inventory_write_off` expense — recognised in the P&L, never a cash
 * movement. Each line's `unit_cost` is the location's weighted-average cost read under
 * the row lock at that moment (StockValuation::costBasis), so the event carries the
 * money and Finance never reaches back into Inventory. Held until commit: a rolled-back
 * adjustment writes nothing off.
 */
final readonly class StockWrittenOff implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<array{variant_id: int, quantity: int, unit_cost: string}>  $lines  quantity > 0, units removed
     * @param  string  $totalCost  Σ quantity × unit_cost, 2-dp decimal string (rule 6)
     */
    public function __construct(
        public int $adjustmentId,
        public string $adjustmentNumber,
        public string $reason,
        public int $locationId,
        public CarbonImmutable $occurredAt,
        public array $lines,
        public string $totalCost,
    ) {}
}
