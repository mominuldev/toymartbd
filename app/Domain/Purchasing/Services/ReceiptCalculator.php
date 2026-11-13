<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Inventory\Actions\ReceivePurchasedStock;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\StockValuation;
use App\Domain\Purchasing\DTOs\ReceiptData;
use App\Domain\Purchasing\DTOs\ReceiptQuote;
use App\Domain\Purchasing\DTOs\ReceiptQuoteLine;
use App\Domain\Purchasing\Enums\LandedCostMethod;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one code path that turns "these units arrived, with this freight" into money:
 * the receive dialog's preview (`POST …/receipts/quote`) and ReceivePurchase both call
 * it, so the figures staff confirm are the figures written (rule 1).
 *
 * Per line:
 *   line_value       = units × (line_total / quantity_ordered), to the poisha. The receipt
 *                      that completes a line takes line_total − Σ earlier receipts instead,
 *                      so a line's receipts always sum to its line_total exactly.
 *   allocated_cost   = the line's share of freight + customs/duty + other
 *                      (LandedCostApportioner; by value unless the receipt says otherwise).
 *   landed_unit_cost = (line_value + allocated_cost) / units, 4 dp.
 *   avg_cost_after   = ReceivePurchasedStock::nextAverage — previewed here from an
 *                      unlocked read; the receipt recomputes it under the row lock.
 *
 * By value with every line valued at zero (free goods) falls back to by quantity, so a
 * freight bill on a free sample still lands somewhere.
 */
final class ReceiptCalculator
{
    /**
     * @throws ValidationException naming `lines`, `lines.N.quantity` or `apportion_method`
     */
    public function quote(Purchase $purchase, ReceiptData $data): ReceiptQuote
    {
        $locationId = $data->locationId ?? $purchase->location_id ?: StockLocation::defaultId();

        /** @var array<int, PurchaseItem> $items */
        $items = $purchase->items->keyBy('id')->all();
        $errors = [];
        $receiving = [];
        foreach ($data->lines as $index => ['purchase_item_id' => $itemId, 'quantity' => $quantity]) {
            $item = $items[$itemId] ?? null;
            if ($item === null) {
                $errors["lines.{$index}.purchase_item_id"] = __('purchasing.receive.unknown_line');

                continue;
            }
            if (isset($receiving[$itemId])) {
                $errors["lines.{$index}.purchase_item_id"] = __('purchasing.receive.duplicate_line');

                continue;
            }
            if ($quantity === 0) {
                continue;
            }
            if ($quantity < 0) {
                $errors["lines.{$index}.quantity"] = __('purchasing.receive.negative');
            } elseif ($quantity > $item->quantityOutstanding()) {
                $errors["lines.{$index}.quantity"] = __('purchasing.receive.over_receipt', ['outstanding' => $item->quantityOutstanding()]);
            }
            $receiving[$itemId] = $quantity;
        }
        if ($errors === [] && $receiving === []) {
            $errors['lines'] = __('purchasing.receive.nothing');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // PO line order, so apportioning ties break the same way every time.
        $lines = array_values(array_filter($purchase->items->all(), static fn (PurchaseItem $i): bool => isset($receiving[$i->id])));
        $variantIds = array_map(static fn (PurchaseItem $i): int => $i->variant_id, $lines);

        $earlier = DB::table('purchase_receipt_items')
            ->whereIn('purchase_item_id', array_map(static fn (PurchaseItem $i): int => $i->id, $lines))
            ->groupBy('purchase_item_id')
            ->selectRaw('purchase_item_id, SUM(line_value) as value')
            ->pluck('value', 'purchase_item_id');
        $levels = DB::table('stock_levels')->where('location_id', $locationId)->whereIn('variant_id', $variantIds)->get(['variant_id', 'on_hand', 'avg_cost'])->keyBy('variant_id');
        $variants = DB::table('product_variants')->whereIn('id', $variantIds)->get(['id', 'cost_price', 'weight_grams'])->keyBy('id');

        $values = [];
        foreach ($lines as $item) {
            $quantity = $receiving[$item->id];
            $values[$item->id] = $item->quantity_received + $quantity === $item->quantity_ordered
                ? $item->line_total->minus(Money::of(self::decimal($earlier[$item->id] ?? '0')))
                : Money::of(Decimals::round(bcdiv(bcmul((string) $quantity, $item->line_total->toDecimal(), 8), (string) $item->quantity_ordered, 8), 2));
        }

        $method = $data->method;
        $weights = array_map(fn (PurchaseItem $item): string => match ($method) {
            LandedCostMethod::Value => $values[$item->id]->toDecimal(),
            LandedCostMethod::Quantity => (string) $receiving[$item->id],
            LandedCostMethod::Weight => (string) ((int) ($variants[$item->variant_id]->weight_grams ?? 0) * $receiving[$item->id]),
        }, $lines);
        $landed = $data->landedTotal();
        $weightSum = array_reduce($weights, static fn (string $sum, string $w): string => bcadd($sum, $w, 8), '0');
        if (! $landed->isZero() && bccomp($weightSum, '0', 8) <= 0) {
            if ($method === LandedCostMethod::Weight) {
                throw ValidationException::withMessages(['apportion_method' => __('purchasing.receive.no_weight')]);
            }
            $weights = array_map(static fn (PurchaseItem $item): string => (string) $receiving[$item->id], $lines);
        }
        $shares = LandedCostApportioner::apportion($landed, $weights);

        $quoted = [];
        foreach ($lines as $index => $item) {
            $quantity = $receiving[$item->id];
            $allocated = $shares[$index];
            $landedUnit = Decimals::round(bcdiv(bcadd($values[$item->id]->toDecimal(), $allocated->toDecimal(), 2), (string) $quantity, 8), 4);
            $level = $levels[$item->variant_id] ?? null;
            $onHand = (int) ($level->on_hand ?? 0);
            $before = StockValuation::costBasis(self::decimal($level->avg_cost ?? null), self::decimal($variants[$item->variant_id]->cost_price ?? null));

            $quoted[] = new ReceiptQuoteLine(
                purchaseItemId: $item->id,
                variantId: $item->variant_id,
                quantity: $quantity,
                quantityOrdered: $item->quantity_ordered,
                quantityReceivedBefore: $item->quantity_received,
                unitCost: Decimals::round(bcdiv($item->line_total->toDecimal(), (string) $item->quantity_ordered, 8), 4),
                lineValue: $values[$item->id],
                allocatedCost: $allocated,
                landedUnitCost: $landedUnit,
                onHand: $onHand,
                avgCostBefore: $before,
                avgCostAfter: ReceivePurchasedStock::nextAverage($onHand, $before, $quantity, $landedUnit),
            );
        }

        return new ReceiptQuote($locationId, $quoted, $method, $data->freight, $data->customsDuty, $data->otherCost, $data->costsPaidToSupplier);
    }

    private static function decimal(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '0';
    }
}
