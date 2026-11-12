<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\StockAdjustmentStatus;
use App\Domain\Inventory\Exceptions\AdjustmentAlreadyDecided;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockAdjustmentItem;
use App\Domain\Inventory\Services\StockAdjustmentLines;
use App\Domain\Inventory\Services\StockLevelLocks;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves a pending stock adjustment (P5-INV) and only then writes its movements.
 *
 * One transaction: the adjustment row is locked first (two approvers clicking at once —
 * the second gets AdjustmentAlreadyDecided, never a second set of movements), then the
 * levels in ascending variant id order (rule 9). Each line is re-checked against the
 * stock as it is NOW, not as it was when requested: a counted line's change is worked
 * out again from the count (the count is the truth; sales since then are already in
 * on_hand), a signed change is applied as asked, and any line that would now break a
 * rule (below reserved, the wrong direction) refuses the whole approval with a 422 —
 * the adjustment stays pending. The lines are rewritten once with what actually moved.
 *
 * The approver must be someone other than the requester (segregation of duties).
 */
final readonly class ApproveStockAdjustment
{
    public function __construct(
        private StockLevelLocks $locks,
        private StockAdjustmentLines $lines,
    ) {}

    /**
     * @throws AdjustmentAlreadyDecided when it is not pending
     * @throws ValidationException naming each line that can no longer apply (`items.N`), or `adjustment` for self-approval
     */
    public function handle(int $adjustmentId, int $approverId, ?string $note = null): StockAdjustment
    {
        return DB::transaction(function () use ($adjustmentId, $approverId, $note): StockAdjustment {
            /** @var StockAdjustment $adjustment */
            $adjustment = StockAdjustment::query()->lockForUpdate()->findOrFail($adjustmentId);
            if ($adjustment->status !== StockAdjustmentStatus::Pending) {
                throw AdjustmentAlreadyDecided::for((string) $adjustment->number, $adjustment->status->value);
            }
            if ($adjustment->user_id !== null && $adjustment->user_id === $approverId) {
                throw ValidationException::withMessages(['adjustment' => __('inventory.approval.self')]);
            }

            /** @var list<StockAdjustmentItem> $items */
            $items = $adjustment->items()->orderBy('variant_id')->get()->all();
            $levels = $this->locks->lock(array_map(static fn (StockAdjustmentItem $i): int => $i->variant_id, $items), $adjustment->location_id);
            $unitCosts = StockAdjustmentLines::unitCosts($levels);

            $changes = [];
            $errors = [];
            foreach ($items as $index => $item) {
                $level = $levels[$item->variant_id];
                $change = $item->counted !== null ? $item->counted - $level->on_hand : (int) $item->requested_change;
                // A counted line that already matches the shelf moves nothing; that is fine.
                $refusal = $change === 0 && $item->counted !== null ? null : StockAdjustmentLines::refusal($adjustment->reason, $level, $change);
                if ($refusal !== null) {
                    $errors['items.'.$index] = $refusal;
                }
                $changes[$item->variant_id] = $change;
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            foreach ($items as $item) {
                $level = $levels[$item->variant_id];
                $item->quantity_before = $level->on_hand;
                $item->quantity_after = $level->on_hand + $changes[$item->variant_id];
                $item->unit_cost = $unitCosts[$item->variant_id];
                $item->save();
            }

            $adjustment->status = StockAdjustmentStatus::Approved;
            $adjustment->approved_by = $approverId;
            $adjustment->approved_at = now();
            $adjustment->decision_note = $note;
            $adjustment->save();

            $this->lines->apply($adjustment, $changes, $unitCosts, $approverId);

            return $adjustment->load('items');
        });
    }
}
