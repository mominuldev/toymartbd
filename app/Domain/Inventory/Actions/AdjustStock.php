<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\StockAdjustmentData;
use App\Domain\Inventory\DTOs\StockAdjustmentLine;
use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockAdjustmentStatus;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockAdjustmentItem;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Inventory\Services\StockLevelLocks;
use App\Domain\Inventory\Services\StockValuation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A manual stock adjustment (P5-INV, pulled forward): damage, loss, found, recount,
 * expiry, correction, gift/sample. One transaction writes the adjustment document, its
 * lines and one ledger movement per line, and moves stock_levels.on_hand by the same
 * amount (invariant §7.1).
 *
 * The levels are locked FOR UPDATE in ascending variant id order (rule 9) before
 * anything is read, so a counted line's change is computed against the on_hand that
 * is actually there, and a concurrent checkout cannot reserve in between. Every line is
 * checked before any is written: an adjustment never takes on_hand below what is
 * reserved for open orders (that would let an order ship stock that is not there), and
 * a reason only moves stock its own way (found adds, damage removes …).
 */
final readonly class AdjustStock
{
    public function __construct(
        private StockLevelLocks $locks,
        private StockLedger $ledger,
    ) {}

    /**
     * @throws ValidationException naming each refused line (`lines.N.quantity` / `lines.N.counted`)
     */
    public function handle(StockAdjustmentData $data, ?int $userId = null): StockAdjustment
    {
        return DB::transaction(function () use ($data, $userId): StockAdjustment {
            $locationId = $data->locationId ?? StockLocation::defaultId();
            $variantIds = array_map(static fn (StockAdjustmentLine $l): int => $l->variantId, $data->lines);

            $levels = $this->locks->lock($variantIds, $locationId);
            $costPrices = DB::table('product_variants')->whereIn('id', $variantIds)->pluck('cost_price', 'id');

            $changes = [];
            $errors = [];
            foreach ($data->lines as $index => $line) {
                $level = $levels[$line->variantId];
                $change = $line->changeFrom($level->on_hand);
                $field = 'lines.'.$index.'.'.($line->counted !== null ? 'counted' : 'quantity');

                if ($change === 0) {
                    $errors[$field] = __('inventory.adjust.no_change');
                } elseif (! $data->reason->allows($change)) {
                    $errors[$field] = __($change > 0 ? 'inventory.adjust.only_out' : 'inventory.adjust.only_in');
                } elseif ($level->on_hand + $change < $level->reserved) {
                    $errors[$field] = __('inventory.adjust.below_reserved', ['reserved' => $level->reserved, 'on_hand' => $level->on_hand]);
                }
                $changes[$line->variantId] = $change;
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $adjustment = StockAdjustment::query()->create([
                'location_id' => $locationId,
                'reason' => $data->reason,
                'note' => $data->note,
                'user_id' => $userId,
                'status' => StockAdjustmentStatus::Applied,
            ]);
            $adjustment->number = StockAdjustment::numberFor($adjustment->id, $adjustment->created_at ?? now());
            $adjustment->save();

            $movements = [];
            foreach ($levels as $variantId => $level) {
                $change = $changes[$variantId];
                $unitCost = StockValuation::costBasis($level->avg_cost, $costPrices[$variantId] ?? null);

                StockAdjustmentItem::query()->create([
                    'adjustment_id' => $adjustment->id,
                    'variant_id' => $variantId,
                    'quantity_before' => $level->on_hand,
                    'quantity_after' => $level->on_hand + $change,
                    'unit_cost' => $unitCost,
                ]);
                $movements[] = new StockMovementLine(
                    $variantId,
                    $data->reason->movementType($change),
                    abs($change),
                    unitCost: $unitCost,
                    referenceType: StockAdjustment::REFERENCE_TYPE,
                    referenceId: $adjustment->id,
                    userId: $userId,
                    note: $data->note,
                );
            }

            $this->ledger->record($movements, $locationId);

            return $adjustment->load('items');
        });
    }
}
