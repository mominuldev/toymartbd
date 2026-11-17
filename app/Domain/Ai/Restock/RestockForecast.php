<?php

declare(strict_types=1);

namespace App\Domain\Ai\Restock;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Services\PurchasingSummary;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Inventory › Restock forecast (Figma 540:83487). Arithmetic only — no AI here; the AI writes a
 * short note about this result afterwards ({@see RestockNote}). Read-only, no transaction.
 *
 * Per variant that sold in the window (variant active, product not archived):
 *
 * - `in_stock` — available units (on hand − reserved) summed over every active location. The
 *   forecast is store-wide: orders are not branch-scoped, and reserved units belong to orders
 *   already counted as sales.
 * - `units_sold` — units on non-cancelled orders (web, phone, social and POS alike) placed in the
 *   last {@see self::WINDOW_DAYS} Dhaka calendar days, today included (rule 11).
 * - `sells_per_day` = units_sold / 30, a 2-dp string (half-up). Every other figure is computed
 *   from the integer units, never from that rounded rate, so nothing drifts.
 * - `runs_out_in_days` = floor(in_stock / rate) = floor(30 × in_stock / units_sold).
 * - `lead_time_days` — the median order-to-received gap of the supplier's last
 *   {@see self::LEAD_SAMPLE} fully received POs (Dhaka dates); the supplier is the one on the
 *   variant's latest placed PO. Unknown → {@see self::DEFAULT_LEAD_DAYS}, flagged `lead_time_estimated`.
 * - `suggested_order` = max(0, ceil(rate × (lead_time + horizon)) − in_stock − open PO units).
 * - `units_short` = max(0, ceil(rate × horizon) − in_stock − open PO units); `sales_at_risk` =
 *   units_short × current price.
 * - `suggested_order_value` = suggested_order × the last purchase unit cost (null when the
 *   variant was never bought). The HTTP layer hides it without catalog.view_cost.
 *
 * Only variants that run out within horizon + their lead time are listed.
 */
final class RestockForecast
{
    /** @var list<int> */
    public const array HORIZONS = [7, 14, 30];

    public const int DEFAULT_HORIZON = 14;

    public const int WINDOW_DAYS = 30;

    public const int DEFAULT_LEAD_DAYS = 7;

    public const int LEAD_SAMPLE = 10;

    public const int HIGH_CONFIDENCE_UNITS = 30;

    public const int MEDIUM_CONFIDENCE_UNITS = 10;

    /**
     * @return array{
     *     horizon: int,
     *     today: string,
     *     window_from: string,
     *     window_to: string,
     *     rows: list<RestockRow>,
     *     running_out: int,
     *     before_arrival: int,
     *     sales_at_risk: Money,
     *     suggested_order_value: Money,
     * }
     */
    public function compute(int $horizon): array
    {
        $today = DhakaDate::today();
        $from = (CarbonImmutable::createFromFormat('!Y-m-d', $today) ?: CarbonImmutable::now())->subDays(self::WINDOW_DAYS - 1)->toDateString();
        [$start] = DhakaDate::utcBounds($from);
        [, $end] = DhakaDate::utcBounds($today);

        $sold = $this->unitsSold($start, $end);
        $variants = $sold === [] ? [] : ProductVariant::query()
            ->with('product:id,name,name_bn,status')
            ->whereIn('id', array_keys($sold))
            ->where('status', 'active')
            ->whereHas('product', static fn ($q) => $q->where('status', '!=', 'archived'))
            ->get()
            ->all();
        $ids = array_map(static fn (ProductVariant $v): int => $v->id, $variants);

        $stock = $this->available($ids);
        $open = $this->openPurchaseUnits($ids);
        $last = $this->lastPurchases($ids);
        $suppliers = array_values(array_unique(array_map(static fn (array $l): int => $l['supplier_id'], $last)));
        $leads = $this->leadTimes($suppliers);
        $names = $suppliers === [] ? [] : Supplier::query()->whereIn('id', $suppliers)->pluck('name', 'id')->all();

        $rows = [];
        foreach ($variants as $variant) {
            $units = $sold[$variant->id];
            $inStock = max(0, $stock[$variant->id] ?? 0);
            $openUnits = max(0, $open[$variant->id] ?? 0);
            $purchase = $last[$variant->id] ?? null;
            $lead = $purchase !== null ? ($leads[$purchase['supplier_id']] ?? null) : null;
            $leadDays = $lead ?? self::DEFAULT_LEAD_DAYS;

            $runsOut = intdiv(self::WINDOW_DAYS * $inStock, $units);
            if ($runsOut > $horizon + $leadDays) {
                continue;
            }

            $suggested = max(0, self::ceilDiv($units * ($leadDays + $horizon), self::WINDOW_DAYS) - $inStock - $openUnits);
            $short = max(0, self::ceilDiv($units * $horizon, self::WINDOW_DAYS) - $inStock - $openUnits);
            $cost = $purchase['unit_cost'] ?? null;

            $rows[] = new RestockRow(
                variantId: $variant->id,
                productId: $variant->product_id,
                productName: $variant->product->name,
                productNameBn: $variant->product->name_bn,
                variantName: $variant->name,
                variantNameBn: $variant->name_bn,
                sku: $variant->sku,
                inStock: $inStock,
                unitsSold: $units,
                sellsPerDay: self::rate($units),
                runsOutInDays: $runsOut,
                leadTimeDays: $leadDays,
                leadTimeEstimated: $lead === null,
                openPurchaseUnits: $openUnits,
                suggestedOrder: $suggested,
                unitsShort: $short,
                confidence: self::confidence($units),
                price: $variant->price,
                salesAtRisk: $variant->price->times($short),
                supplierId: $purchase['supplier_id'] ?? null,
                supplierName: $purchase !== null ? ($names[$purchase['supplier_id']] ?? null) : null,
                lastUnitCost: $cost,
                suggestedOrderValue: $cost !== null ? Money::of(bcmul($cost, (string) $suggested, 4)) : null,
            );
        }

        usort($rows, static fn (RestockRow $a, RestockRow $b): int => [$a->runsOutInDays, -$a->suggestedOrder, $a->sku] <=> [$b->runsOutInDays, -$b->suggestedOrder, $b->sku]);

        $atRisk = Money::zero();
        $value = Money::zero();
        $runningOut = 0;
        $beforeArrival = 0;
        foreach ($rows as $row) {
            $atRisk = $atRisk->plus($row->salesAtRisk);
            if ($row->suggestedOrderValue !== null) {
                $value = $value->plus($row->suggestedOrderValue);
            }
            $runningOut += $row->runsOutInDays <= $horizon ? 1 : 0;
            $beforeArrival += $row->runsOutInDays < $row->leadTimeDays ? 1 : 0;
        }

        return [
            'horizon' => $horizon,
            'today' => $today,
            'window_from' => $from,
            'window_to' => $today,
            'rows' => $rows,
            'running_out' => $runningOut,
            'before_arrival' => $beforeArrival,
            'sales_at_risk' => $atRisk,
            'suggested_order_value' => $value,
        ];
    }

    public static function confidence(int $unitsSold): string
    {
        return match (true) {
            $unitsSold >= self::HIGH_CONFIDENCE_UNITS => 'high',
            $unitsSold >= self::MEDIUM_CONFIDENCE_UNITS => 'medium',
            default => 'low',
        };
    }

    /** units / 30 as a 2-dp string, half-up, from integers only ("4.00", "0.03"). */
    public static function rate(int $units): string
    {
        $hundredths = intdiv(2 * 100 * $units + self::WINDOW_DAYS, 2 * self::WINDOW_DAYS);

        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    private static function ceilDiv(int $a, int $b): int
    {
        return intdiv($a + $b - 1, $b);
    }

    /** @return array<int, int> variant_id => units on non-cancelled orders placed in [start, end) */
    private function unitsSold(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->where('orders.placed_at', '>=', $start)
            ->where('orders.placed_at', '<', $end)
            ->whereNotNull('order_items.variant_id')
            ->groupBy('order_items.variant_id')
            ->selectRaw('order_items.variant_id AS variant_id, SUM(order_items.quantity) AS units')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $units = (int) $row->units;
            if ($units > 0) {
                $out[(int) $row->variant_id] = $units;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int> variant_id => on hand − reserved over active locations
     */
    private function available(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('stock_levels')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')
            ->where('stock_locations.is_active', true)
            ->whereIn('stock_levels.variant_id', $ids)
            ->groupBy('stock_levels.variant_id')
            ->selectRaw('stock_levels.variant_id AS variant_id, SUM(stock_levels.on_hand - stock_levels.reserved) AS units')
            ->get()
            ->mapWithKeys(static fn (object $r): array => [(int) $r->variant_id => (int) $r->units])
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int> variant_id => units still to arrive on ordered / partly received POs
     */
    private function openPurchaseUnits(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereIn('purchases.status', PurchasingSummary::openStatuses())
            ->whereIn('purchase_items.variant_id', $ids)
            ->whereColumn('purchase_items.quantity_ordered', '>', 'purchase_items.quantity_received')
            ->groupBy('purchase_items.variant_id')
            ->selectRaw('purchase_items.variant_id AS variant_id, SUM(purchase_items.quantity_ordered - purchase_items.quantity_received) AS units')
            ->get()
            ->mapWithKeys(static fn (object $r): array => [(int) $r->variant_id => (int) $r->units])
            ->all();
    }

    /**
     * The latest placed (not draft, not cancelled) PO line per variant: its supplier and unit cost.
     *
     * @param  list<int>  $ids
     * @return array<int, array{supplier_id: int, unit_cost: string}>
     */
    private function lastPurchases(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $lines = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereNotIn('purchases.status', [PurchaseStatus::Draft->value, PurchaseStatus::Cancelled->value])
            ->whereIn('purchase_items.variant_id', $ids)
            ->orderByDesc('purchases.order_date')
            ->orderByDesc('purchases.id')
            ->get(['purchase_items.variant_id', 'purchases.supplier_id', 'purchase_items.unit_cost']);

        $out = [];
        foreach ($lines as $line) {
            $variant = (int) $line->variant_id;
            if (! isset($out[$variant])) {
                $out[$variant] = ['supplier_id' => (int) $line->supplier_id, 'unit_cost' => self::decimal4($line->unit_cost)];
            }
        }

        return $out;
    }

    /**
     * Median order-to-received days per supplier over its last fully received POs.
     *
     * @param  list<int>  $supplierIds
     * @return array<int, int>
     */
    private function leadTimes(array $supplierIds): array
    {
        if ($supplierIds === []) {
            return [];
        }

        $gaps = [];
        $purchases = Purchase::query()
            ->whereIn('supplier_id', $supplierIds)
            ->where('status', PurchaseStatus::Received->value)
            ->whereNotNull('received_at')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get(['id', 'supplier_id', 'order_date', 'received_at']);
        foreach ($purchases as $purchase) {
            if (count($gaps[$purchase->supplier_id] ?? []) >= self::LEAD_SAMPLE || $purchase->received_at === null) {
                continue;
            }
            $ordered = CarbonImmutable::createFromFormat('!Y-m-d', $purchase->order_date->toDateString(), DhakaDate::zone());
            $received = CarbonImmutable::createFromFormat('!Y-m-d', DhakaDate::dayOf($purchase->received_at), DhakaDate::zone());
            if ($ordered === null || $received === null) {
                continue;
            }
            $gaps[$purchase->supplier_id][] = max(1, (int) $ordered->diffInDays($received));
        }

        $out = [];
        foreach ($gaps as $supplier => $days) {
            sort($days);
            $n = count($days);
            $mid = intdiv($n, 2);
            $out[$supplier] = $n % 2 === 1 ? $days[$mid] : intdiv($days[$mid - 1] + $days[$mid] + 1, 2);
        }

        return $out;
    }

    /** A DECIMAL(14,4) column as a 4-dp string (SQLite hands DECIMAL back as a float). */
    private static function decimal4(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 4, '.', '');
        }

        return bcadd(is_string($value) && is_numeric($value) ? $value : '0', '0', 4);
    }
}
