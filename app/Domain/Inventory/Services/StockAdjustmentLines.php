<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockAdjustmentReason;
use App\Domain\Inventory\Events\StockWrittenOff;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockLevel;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The rules and the write shared by AdjustStock (request) and ApproveStockAdjustment:
 * which change a reason allows against a locked level, the size an approval threshold
 * measures, and — once an adjustment is applied or approved — its ledger movements and
 * write-off announcement. Owns no transaction; the calling Action does (rule 3).
 *
 * @internal for AdjustStock and ApproveStockAdjustment
 */
final readonly class StockAdjustmentLines
{
    public function __construct(private StockLedger $ledger) {}

    /** Why a change is refused against the locked level (a translated message), or null. */
    public static function refusal(StockAdjustmentReason $reason, StockLevel $level, int $change): ?string
    {
        return match (true) {
            $change === 0 => __('inventory.adjust.no_change'),
            ! $reason->allows($change) => __($change > 0 ? 'inventory.adjust.only_out' : 'inventory.adjust.only_in'),
            $level->on_hand + $change < $level->reserved => __('inventory.adjust.below_reserved', ['reserved' => $level->reserved, 'on_hand' => $level->on_hand]),
            default => null,
        };
    }

    /**
     * Each variant's unit cost basis at the location: the weighted-average cost on the
     * locked level, else the variant's cost price (StockValuation::costBasis).
     *
     * @param  array<int, StockLevel>  $levels  variant id → locked level
     * @return array<int, string> variant id → 4-dp cost
     */
    public static function unitCosts(array $levels): array
    {
        $costPrices = DB::table('product_variants')->whereIn('id', array_keys($levels))->pluck('cost_price', 'id');
        $costs = [];
        foreach ($levels as $variantId => $level) {
            $costs[$variantId] = StockValuation::costBasis($level->avg_cost, $costPrices[$variantId] ?? null);
        }

        return $costs;
    }

    /**
     * Units moved (absolute) and their value at cost — what the approval threshold reads.
     *
     * @param  array<int, int>  $changes  variant id → signed change
     * @param  array<int, string>  $unitCosts
     * @return array{units: int, value: Money}
     */
    public static function size(array $changes, array $unitCosts): array
    {
        $units = 0;
        $value = Money::zero();
        foreach ($changes as $variantId => $change) {
            $units += abs($change);
            $value = $value->plus(Money::of(StockValuation::value(abs($change), $unitCosts[$variantId])));
        }

        return ['units' => $units, 'value' => $value];
    }

    /** Whether a change of this size needs an approver (config/inventory.php, PLACEHOLDER limits). */
    public static function needsApproval(int $units, Money $value): bool
    {
        $maxUnits = (int) config('inventory.adjustment_approval.max_units', 20);
        $maxValue = Money::of((string) config('inventory.adjustment_approval.max_value', '5000.00'));

        return $units > $maxUnits || $value->greaterThan($maxValue);
    }

    /**
     * Write the ledger movements of an applied or approved adjustment (zero changes are
     * skipped — a counted line that already matches moves nothing) and, for a write-off
     * reason, announce the units removed at their cost.
     *
     * @param  array<int, int>  $changes  variant id → signed change
     * @param  array<int, string>  $unitCosts  variant id → 4-dp cost
     */
    public function apply(StockAdjustment $adjustment, array $changes, array $unitCosts, ?int $userId): void
    {
        $movements = [];
        $writtenOff = [];
        $total = Money::zero();
        ksort($changes);
        foreach ($changes as $variantId => $change) {
            if ($change === 0) {
                continue;
            }
            $movements[] = new StockMovementLine(
                $variantId,
                $adjustment->reason->movementType($change),
                abs($change),
                unitCost: $unitCosts[$variantId],
                referenceType: StockAdjustment::REFERENCE_TYPE,
                referenceId: $adjustment->id,
                userId: $userId,
                note: $adjustment->note,
            );
            if ($change < 0 && $adjustment->reason->isWriteOff()) {
                $writtenOff[] = ['variant_id' => $variantId, 'quantity' => -$change, 'unit_cost' => $unitCosts[$variantId]];
                $total = $total->plus(Money::of(StockValuation::value(-$change, $unitCosts[$variantId])));
            }
        }

        $this->ledger->record($movements, $adjustment->location_id);

        if ($writtenOff !== []) {
            StockWrittenOff::dispatch(
                $adjustment->id,
                (string) $adjustment->number,
                $adjustment->reason->value,
                $adjustment->location_id,
                CarbonImmutable::now(),
                $writtenOff,
                $total->toDecimal(),
            );
        }
    }
}
