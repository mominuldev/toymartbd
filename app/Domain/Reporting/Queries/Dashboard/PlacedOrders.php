<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Dashboard;

use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Domain\Finance\Reports\ReportMoney;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Reporting\Support\DateRange;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Orders **placed** in a range — demand, not revenue (rule 10). Every order placed in the
 * range counts, cancelled ones included, exactly as 04-admin-spec B1 card 1 ("gross order
 * value" ৳37,820 over 12 orders, one of them cancelled). Revenue is recognised on delivery and
 * read from the ledger elsewhere ({@see LedgerFigures}); never add the two together.
 *
 * A day is the Dhaka calendar day of `orders.placed_at` (rule 11). The WHERE stays on the raw
 * column via the range's UTC bounds; each row is bucketed in PHP with DhakaDate, so the same
 * code is exact on MySQL and on the SQLite test driver.
 *
 * value = Σ grand_total; units = Σ order_items.quantity; AOV = value ÷ count, to the poisha,
 * computed here and never in a client (rule 1).
 */
final class PlacedOrders
{
    /**
     * @return array{
     *     count: int, value: Money, units: int, aov: Money|null,
     *     buckets: list<array{key: string, from: string, to: string, count: int, value: Money, units: int, aov: Money|null}>,
     *     customer_ids: list<int>
     * }
     */
    public function scan(DateRange $range, ReportGranularity $granularity): array
    {
        [$start, $end] = $range->utcBounds();

        $units = DB::table('order_items')
            ->selectRaw('SUM(order_items.quantity)')
            ->whereColumn('order_items.order_id', 'orders.id');

        $buckets = [];
        foreach ($range->buckets($granularity) as $bucket) {
            $buckets[$bucket['key']] = [...$bucket, 'count' => 0, 'value' => Money::zero(), 'units' => 0, 'aov' => null];
        }

        $count = 0;
        $value = Money::zero();
        $unitTotal = 0;
        $customers = [];

        $rows = DB::table('orders')
            ->where('placed_at', '>=', $start)
            ->where('placed_at', '<', $end)
            ->select(['id', 'customer_id', 'placed_at', 'grand_total'])
            ->selectSub($units, 'units')
            ->lazyById(1000, 'id');

        foreach ($rows as $row) {
            $day = DhakaDate::dayOf(CarbonImmutable::parse((string) $row->placed_at, 'UTC'));
            $key = $range->bucketOf($day, $granularity);
            $amount = ReportMoney::of($row->grand_total);
            $orderUnits = (int) ($row->units ?? 0);

            $count++;
            $value = $value->plus($amount);
            $unitTotal += $orderUnits;
            $customers[(int) $row->customer_id] = true;

            if (isset($buckets[$key])) {
                $buckets[$key]['count']++;
                $buckets[$key]['value'] = $buckets[$key]['value']->plus($amount);
                $buckets[$key]['units'] += $orderUnits;
            }
        }

        foreach ($buckets as $key => $bucket) {
            $buckets[$key]['aov'] = self::average($bucket['value'], $bucket['count']);
        }

        return [
            'count' => $count,
            'value' => $value,
            'units' => $unitTotal,
            'aov' => self::average($value, $count),
            'buckets' => array_values($buckets),
            'customer_ids' => array_keys($customers),
        ];
    }

    /**
     * New vs returning among the customers who placed an order in the range: **new** = their
     * first-ever order falls inside the range; **returning** = they had ordered before it. Also
     * the new customers per bucket (the customers card sparkline).
     *
     * @param  list<int>  $customerIds  the customers who placed an order in the range ({@see self::scan()})
     * @return array{new: int, returning: int, new_by_bucket: array<string, int>}
     */
    public function customers(DateRange $range, ReportGranularity $granularity, array $customerIds): array
    {
        [$start] = $range->utcBounds();

        $byBucket = [];
        foreach ($range->buckets($granularity) as $bucket) {
            $byBucket[$bucket['key']] = 0;
        }

        $new = 0;
        foreach (array_chunk($customerIds, 1000) as $chunk) {
            $firsts = DB::table('orders')
                ->whereIn('customer_id', $chunk)
                ->whereNotNull('placed_at')
                ->groupBy('customer_id')
                ->select('customer_id')
                ->selectRaw('MIN(placed_at) as first_placed_at')
                ->get();
            foreach ($firsts as $row) {
                $first = CarbonImmutable::parse((string) $row->first_placed_at, 'UTC');
                if ($first->lessThan($start)) {
                    continue;
                }
                $new++;
                $key = $range->bucketOf(DhakaDate::dayOf($first), $granularity);
                if (isset($byBucket[$key])) {
                    $byBucket[$key]++;
                }
            }
        }

        return ['new' => $new, 'returning' => count($customerIds) - $new, 'new_by_bucket' => $byBucket];
    }

    /**
     * Top products by units over the orders placed in the range, cancelled orders excluded
     * (a cancelled order sold nothing). `value` is the lines' order value (Σ line_total, after
     * line discounts) — placed demand, not recognised revenue.
     *
     * @return array{rows: list<array{product_id: int|null, name: string, name_bn: string|null, sku: string, image_path: string|null, units: int, value: Money}>, units: int, products: int, value: Money}
     */
    public function bestSellers(DateRange $range, int $limit = 5): array
    {
        [$start, $end] = $range->utcBounds();

        $lines = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.placed_at', '>=', $start)
            ->where('orders.placed_at', '<', $end)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->select([
                'order_items.id', 'order_items.product_id', 'order_items.product_name', 'order_items.product_name_bn', 'order_items.sku',
                'order_items.image_path', 'order_items.quantity', 'order_items.line_total', 'products.name as current_name', 'products.name_bn as current_name_bn',
            ])
            ->lazyById(1000, 'order_items.id', 'id');

        $groups = [];
        $units = 0;
        $value = Money::zero();
        foreach ($lines as $line) {
            $key = $line->product_id !== null ? 'p'.$line->product_id : 's'.$line->sku;
            $amount = ReportMoney::of($line->line_total);
            $groups[$key] ??= [
                'product_id' => $line->product_id === null ? null : (int) $line->product_id,
                'name' => (string) ($line->current_name ?? $line->product_name),
                'name_bn' => $line->current_name_bn ?? $line->product_name_bn,
                'sku' => (string) $line->sku,
                'image_path' => $line->image_path,
                'units' => 0,
                'value' => Money::zero(),
            ];
            $groups[$key]['units'] += (int) $line->quantity;
            $groups[$key]['value'] = $groups[$key]['value']->plus($amount);
            if ($groups[$key]['image_path'] === null && $line->image_path !== null) {
                $groups[$key]['image_path'] = $line->image_path;
            }
            $units += (int) $line->quantity;
            $value = $value->plus($amount);
        }

        $rows = array_values($groups);
        usort($rows, static fn (array $a, array $b): int => [$b['units'], $b['value']->toPoisha(), $a['name']] <=> [$a['units'], $a['value']->toPoisha(), $b['name']]);

        return [
            'rows' => array_slice($rows, 0, $limit),
            'units' => $units,
            'products' => count($rows),
            'value' => $value,
        ];
    }

    private static function average(Money $value, int $count): ?Money
    {
        if ($count === 0) {
            return null;
        }

        return Money::of(ReportMoney::round(bcdiv($value->toDecimal(), (string) $count, 8)));
    }
}
