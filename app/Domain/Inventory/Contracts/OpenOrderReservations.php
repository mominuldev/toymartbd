<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Contracts;

/**
 * What stock_levels.reserved SHOULD be: the summed quantity of open orders per variant
 * and location (DATABASE-DESIGN §7 invariant 5). Only Ordering knows which order states
 * hold a reservation, so Ordering implements this; Inventory's nightly reconciliation
 * compares it with the levels. Until a binding exists the reserved check is skipped
 * (and the reconciliation result says so) rather than guessed.
 */
interface OpenOrderReservations
{
    /** @return array<int, array<int, int>> location id → variant id → reserved quantity */
    public function quantities(): array;
}
