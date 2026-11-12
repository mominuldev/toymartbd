<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\StockAdjustmentData;
use App\Domain\Inventory\DTOs\StockAdjustmentLine;
use App\Domain\Inventory\Enums\StockAdjustmentStatus;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockAdjustmentItem;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\StockAdjustmentLines;
use App\Domain\Inventory\Services\StockLevelLocks;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A manual stock adjustment (P5-INV): damage, loss, theft, found, recount, expiry,
 * correction, gift/sample. One transaction writes the adjustment document and its lines,
 * and — unless it needs approval — one ledger movement per line, moving
 * stock_levels.on_hand by the same amount (invariant §7.1).
 *
 * The levels are locked FOR UPDATE in ascending variant id order (rule 9) before
 * anything is read, so a counted line's change is computed against the on_hand that
 * is actually there, and a concurrent checkout cannot reserve in between. Every line is
 * checked before any is written: an adjustment never takes on_hand below what is
 * reserved for open orders (that would let an order ship stock that is not there), and
 * a reason only moves stock its own way (found adds, damage removes …).
 *
 * Approval (config/inventory.php): an adjustment moving more units, or more value at
 * weighted-average cost, than the PLACEHOLDER limits is saved `pending` with no
 * movement, for ApproveStockAdjustment / RejectStockAdjustment. A requester who may
 * approve (`$canApprove`) applies it at once, recorded as approved by them.
 */
final readonly class AdjustStock
{
    public function __construct(
        private StockLevelLocks $locks,
        private StockAdjustmentLines $lines,
    ) {}

    /**
     * @throws ValidationException naming each refused line (`lines.N.quantity` / `lines.N.counted`)
     */
    public function handle(StockAdjustmentData $data, ?int $userId = null, bool $canApprove = false): StockAdjustment
    {
        return DB::transaction(function () use ($data, $userId, $canApprove): StockAdjustment {
            $locationId = $data->locationId ?? StockLocation::defaultId();
            $variantIds = array_map(static fn (StockAdjustmentLine $l): int => $l->variantId, $data->lines);

            $levels = $this->locks->lock($variantIds, $locationId);
            $unitCosts = StockAdjustmentLines::unitCosts($levels);

            $changes = [];
            $errors = [];
            foreach ($data->lines as $index => $line) {
                $change = $line->changeFrom($levels[$line->variantId]->on_hand);
                $refusal = StockAdjustmentLines::refusal($data->reason, $levels[$line->variantId], $change);
                if ($refusal !== null) {
                    $errors['lines.'.$index.'.'.($line->counted !== null ? 'counted' : 'quantity')] = $refusal;
                }
                $changes[$line->variantId] = $change;
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $size = StockAdjustmentLines::size($changes, $unitCosts);
            $status = match (true) {
                ! StockAdjustmentLines::needsApproval($size['units'], $size['value']) => StockAdjustmentStatus::Applied,
                $canApprove => StockAdjustmentStatus::Approved,
                default => StockAdjustmentStatus::Pending,
            };

            $adjustment = StockAdjustment::query()->create([
                'location_id' => $locationId,
                'reason' => $data->reason,
                'note' => $data->note,
                'user_id' => $userId,
                'status' => $status,
                'requested_units' => $size['units'],
                'requested_value' => $size['value'],
                'approved_by' => $status === StockAdjustmentStatus::Approved ? $userId : null,
                'approved_at' => $status === StockAdjustmentStatus::Approved ? now() : null,
            ]);
            $adjustment->number = StockAdjustment::numberFor($adjustment->id, $adjustment->created_at ?? now());
            $adjustment->save();

            foreach ($data->lines as $line) {
                $level = $levels[$line->variantId];
                StockAdjustmentItem::query()->create([
                    'adjustment_id' => $adjustment->id,
                    'variant_id' => $line->variantId,
                    'requested_change' => $line->counted === null ? $line->quantity : null,
                    'counted' => $line->counted,
                    'quantity_before' => $level->on_hand,
                    'quantity_after' => $level->on_hand + $changes[$line->variantId],
                    'unit_cost' => $unitCosts[$line->variantId],
                ]);
            }

            if ($status->movedStock()) {
                $this->lines->apply($adjustment, $changes, $unitCosts, $userId);
            }

            return $adjustment->load('items');
        });
    }
}
