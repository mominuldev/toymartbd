<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\StockLedgerViolation;
use App\Domain\Inventory\Models\StockLocation;
use Illuminate\Support\Facades\DB;

/**
 * Reservation helper (P4-CRT-05, DATABASE-DESIGN §4.1): stock is held against
 * `reserved` at order placement and only consumed (a `sale_out` movement) at dispatch,
 * because many COD orders cancel before shipping. Reserving writes no movement —
 * on_hand is unchanged — so the ledger invariant is untouched.
 *
 * Owns no transaction (rule 3); refuses to run outside the caller's. Locks in ascending
 * variant id order (rule 9) and checks every line before changing any: a reservation is
 * all or nothing, which is what makes two concurrent checkouts unable to oversell.
 */
final readonly class StockReservations
{
    public function __construct(private StockLevelLocks $locks) {}

    /**
     * @param  array<int, int>  $lines  variant id → quantity (> 0)
     *
     * @throws InsufficientStock listing every short line
     */
    public function reserve(array $lines, ?int $locationId = null): void
    {
        if (! $this->guard($lines)) {
            return;
        }
        $levels = $this->locks->lock(array_keys($lines), $locationId ??= StockLocation::defaultId());

        $short = [];
        foreach ($levels as $variantId => $level) {
            if ($level->available() < $lines[$variantId]) {
                $short[$variantId] = ['requested' => $lines[$variantId], 'available' => max(0, $level->available())];
            }
        }
        if ($short !== []) {
            throw InsufficientStock::forLines($short);
        }

        foreach ($levels as $variantId => $level) {
            $level->reserved += $lines[$variantId];
            $level->save();
        }

        $this->locks->announce(array_keys($levels));
    }

    /**
     * Give reserved stock back (order cancelled before dispatch, or a reservation
     * expired). Releasing more than is reserved is a double release — a bug — and throws.
     *
     * @param  array<int, int>  $lines  variant id → quantity (> 0)
     */
    public function release(array $lines, ?int $locationId = null): void
    {
        if (! $this->guard($lines)) {
            return;
        }
        $levels = $this->locks->lock(array_keys($lines), $locationId ??= StockLocation::defaultId());

        foreach ($levels as $variantId => $level) {
            if ($level->reserved < $lines[$variantId]) {
                throw StockLedgerViolation::releaseExceedsReserved($variantId);
            }
        }

        foreach ($levels as $variantId => $level) {
            $level->reserved -= $lines[$variantId];
            $level->save();
        }

        $this->locks->announce(array_keys($levels));
    }

    /**
     * @param  array<int, int>  $lines
     * @return bool false when there is nothing to do
     */
    private function guard(array $lines): bool
    {
        if (DB::transactionLevel() === 0) {
            throw StockLedgerViolation::outsideTransaction();
        }
        foreach ($lines as $quantity) {
            if ($quantity <= 0) {
                throw StockLedgerViolation::nonPositiveQuantity();
            }
        }

        return $lines !== [];
    }
}
