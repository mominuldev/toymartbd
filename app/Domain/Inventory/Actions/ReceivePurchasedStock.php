<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\StockLedgerViolation;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Inventory\Services\StockLevelLocks;
use App\Domain\Inventory\Services\StockValuation;
use Illuminate\Support\Facades\DB;

/**
 * Stock arriving from a supplier (DATABASE-DESIGN §4.6, §5.3): one `purchase_in`
 * movement per variant at the landed unit cost, and the weighted-average cost step,
 * in one transaction.
 *
 * The level rows are locked FOR UPDATE in ascending variant id order (rule 9) before
 * anything is read, so the average is computed against the on_hand and avg_cost that
 * are really there:
 *
 *     new_avg = (on_hand × old_avg + qty × landed_unit_cost) / (on_hand + qty)
 *
 * `old_avg` is the location's cost basis (StockValuation::costBasis — the variant's
 * cost_price until a receipt has set avg_cost). When on_hand is zero or negative the old
 * units carry no value worth averaging, so the new average is the landed cost itself —
 * otherwise a negative balance would push the average past the price paid, or divide
 * by zero.
 *
 * The variant's `cost_price` (current replacement cost, §3.1) is set to the new average
 * at the receiving location in the same transaction. `order_items.cost_price_snapshot`
 * is never touched (rule 7): orders already placed keep the cost they were placed at.
 *
 * Called by Purchasing's ReceivePurchase inside its own transaction (this one nests as a
 * savepoint); it never knows what a purchase is beyond the reference it is given.
 */
final readonly class ReceivePurchasedStock
{
    public function __construct(
        private StockLevelLocks $locks,
        private StockLedger $ledger,
    ) {}

    /**
     * @param  list<array{variant_id: int, quantity: int, landed_unit_cost: string}>  $lines  one per variant
     * @return array<int, array{on_hand_before: int, avg_cost_before: string, avg_cost_after: string}> by variant id
     */
    public function handle(
        int $locationId,
        array $lines,
        string $referenceType,
        int $referenceId,
        ?int $userId = null,
        ?string $note = null,
    ): array {
        if ($lines === []) {
            return [];
        }
        foreach ($lines as $line) {
            if ($line['quantity'] <= 0) {
                throw StockLedgerViolation::nonPositiveQuantity();
            }
        }
        usort($lines, static fn (array $a, array $b): int => $a['variant_id'] <=> $b['variant_id']);

        return DB::transaction(function () use ($locationId, $lines, $referenceType, $referenceId, $userId, $note): array {
            $variantIds = array_map(static fn (array $l): int => $l['variant_id'], $lines);
            $levels = $this->locks->lock($variantIds, $locationId);
            $costPrices = DB::table('product_variants')->whereIn('id', $variantIds)->pluck('cost_price', 'id');

            $result = [];
            $movements = [];
            foreach ($lines as $line) {
                $variantId = $line['variant_id'];
                $level = $levels[$variantId];
                $before = StockValuation::costBasis($level->avg_cost, $costPrices[$variantId] ?? null);
                $after = self::nextAverage($level->on_hand, $before, $line['quantity'], $line['landed_unit_cost']);

                $result[$variantId] = ['on_hand_before' => $level->on_hand, 'avg_cost_before' => $before, 'avg_cost_after' => $after];

                // Saved before the ledger re-reads the locked row, so its on_hand write keeps it.
                $level->avg_cost = $after;
                $level->save();

                $movements[] = new StockMovementLine(
                    $variantId,
                    StockMovementType::PurchaseIn,
                    $line['quantity'],
                    unitCost: self::scale4($line['landed_unit_cost']),
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    userId: $userId,
                    note: $note,
                );
            }

            $this->ledger->record($movements, $locationId);

            $now = now();
            foreach ($result as $variantId => $step) {
                DB::table('product_variants')->where('id', $variantId)->update([
                    'cost_price' => self::round2($step['avg_cost_after']),
                    'updated_at' => $now,
                ]);
            }

            return $result;
        });
    }

    /**
     * The weighted-average step, exact in bcmath and rounded half-up to 4 dp (the
     * `avg_cost` scale). Pure: the receive quote shows the same figure the receipt writes.
     */
    public static function nextAverage(int $onHand, string $oldAverage, int $quantity, string $landedUnitCost): string
    {
        if ($quantity <= 0) {
            return self::scale4($oldAverage);
        }
        if ($onHand <= 0) {
            return self::scale4($landedUnitCost);
        }

        $value = bcadd(bcmul((string) $onHand, $oldAverage, 8), bcmul((string) $quantity, $landedUnitCost, 8), 8);

        return self::scale4(bcdiv($value, (string) ($onHand + $quantity), 8));
    }

    /** Half away from zero at the fifth decimal; costs are never negative. */
    private static function scale4(string $value): string
    {
        $half = str_starts_with($value, '-') ? '-0.00005' : '0.00005';

        return bcadd(bcadd($value, $half, 8), '0', 4);
    }

    private static function round2(string $value): string
    {
        $half = str_starts_with($value, '-') ? '-0.005' : '0.005';

        return bcadd(bcadd($value, $half, 8), '0', 2);
    }
}
