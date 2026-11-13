<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\StockAdjustmentStatus;
use App\Domain\Inventory\Exceptions\AdjustmentAlreadyDecided;
use App\Domain\Inventory\Models\StockAdjustment;
use Illuminate\Support\Facades\DB;

/**
 * Rejects a pending stock adjustment (P5-INV). Writes nothing but the decision: a
 * rejected adjustment never had, and never will have, a stock movement. The row is
 * locked so a concurrent approve and reject cannot both win.
 */
final readonly class RejectStockAdjustment
{
    /** @throws AdjustmentAlreadyDecided when it is not pending */
    public function handle(int $adjustmentId, int $rejecterId, string $note): StockAdjustment
    {
        return DB::transaction(function () use ($adjustmentId, $rejecterId, $note): StockAdjustment {
            /** @var StockAdjustment $adjustment */
            $adjustment = StockAdjustment::query()->lockForUpdate()->findOrFail($adjustmentId);
            if ($adjustment->status !== StockAdjustmentStatus::Pending) {
                throw AdjustmentAlreadyDecided::for((string) $adjustment->number, $adjustment->status->value);
            }

            $adjustment->status = StockAdjustmentStatus::Rejected;
            $adjustment->rejected_by = $rejecterId;
            $adjustment->rejected_at = now();
            $adjustment->decision_note = $note;
            $adjustment->save();

            return $adjustment->load('items');
        });
    }
}
