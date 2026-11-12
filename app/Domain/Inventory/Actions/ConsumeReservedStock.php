<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\StockLedgerViolation;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Inventory\Services\StockLevelLocks;
use Illuminate\Support\Facades\DB;

/**
 * Dispatch: the reservation held since placement becomes a sale (DATABASE-DESIGN §4.2).
 * In one transaction, for every line:
 *
 *   stock_levels locked FOR UPDATE in ascending variant id (rule 9) →
 *   reserved -= qty (consuming more than is reserved is a bug and throws) →
 *   a `sale_out` movement with balance_after, on_hand -= qty (through StockLedger, so
 *   Σ movements = on_hand still holds).
 *
 * Each movement carries the order line's snapshot unit cost (rule 7) and points at the
 * order. Called inside the shipping Action's transaction (nested: a savepoint); standalone
 * it opens its own.
 */
final readonly class ConsumeReservedStock
{
    public function __construct(
        private StockLevelLocks $locks,
        private StockLedger $ledger,
    ) {}

    /**
     * @param  list<array{variant_id: int, quantity: int, unit_cost: string|null}>  $lines  one per order line; a variant may repeat
     * @return list<StockMovement>
     *
     * @throws InsufficientStock when on_hand cannot cover a line (a miscount — fix with an adjustment)
     */
    public function handle(array $lines, string $referenceType, int $referenceId, ?int $userId = null, ?int $locationId = null): array
    {
        $totals = [];
        foreach ($lines as $line) {
            if ($line['quantity'] <= 0) {
                throw StockLedgerViolation::nonPositiveQuantity();
            }
            $totals[$line['variant_id']] = ($totals[$line['variant_id']] ?? 0) + $line['quantity'];
        }
        if ($totals === []) {
            return [];
        }
        ksort($totals);

        return DB::transaction(function () use ($lines, $totals, $referenceType, $referenceId, $userId, $locationId): array {
            $locationId ??= StockLocation::defaultId();
            $levels = $this->locks->lock(array_keys($totals), $locationId);

            foreach ($levels as $variantId => $level) {
                if ($level->reserved < $totals[$variantId]) {
                    throw StockLedgerViolation::releaseExceedsReserved($variantId);
                }
            }
            foreach ($levels as $variantId => $level) {
                $level->reserved -= $totals[$variantId];
                $level->save();
            }

            // StockLedger re-locks the same rows (already ours) in the same ascending order,
            // checks on_hand for every line before writing, and announces the change.
            return $this->ledger->record(array_map(
                static fn (array $line): StockMovementLine => new StockMovementLine(
                    $line['variant_id'], StockMovementType::SaleOut, $line['quantity'], $line['unit_cost'],
                    $referenceType, $referenceId, $userId, 'shipped',
                ),
                $lines,
            ), $locationId);
        });
    }
}
