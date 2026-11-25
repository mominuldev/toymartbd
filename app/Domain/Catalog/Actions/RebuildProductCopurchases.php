<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Events\ProductCopurchasesRebuilt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the `product_copurchases` projection (P10, PRD X-04 frequently bought together):
 * for every ordered pair of distinct products, the number of distinct non-cancelled orders
 * placed in the last {@see self::WINDOW_DAYS} days that contained both, and the latest such order.
 * Both directions are stored — the self-join on `order_items` produces (a, b) and (b, a).
 *
 * The aggregate is one set-based SELECT (portable to MySQL 8.4 and SQLite), run as a plain
 * consistent read BEFORE the transaction: an `INSERT … SELECT` would take shared locks on
 * `order_items`/`orders` rows on InnoDB and could stall checkout. The swap — delete every row,
 * insert the fresh set — is one transaction, so a reader sees the old projection or the new
 * one, never a half-built one.
 *
 * Catalog may not depend on Ordering (deptrac), so it reads `orders` and `order_items` by
 * table name. `cancelled` is OrderStatus::Cancelled's stored value.
 */
final class RebuildProductCopurchases
{
    public const int WINDOW_DAYS = 180;

    private const int INSERT_CHUNK = 500;

    /** @return array{pairs: int, since: CarbonImmutable, computed_at: CarbonImmutable} */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $since = $now->subDays(self::WINDOW_DAYS);
        $placedAt = 'COALESCE(o.placed_at, o.created_at)';

        $pairs = DB::table('order_items as a')
            ->join('order_items as b', static function ($join): void {
                $join->on('b.order_id', '=', 'a.order_id')->on('b.product_id', '<>', 'a.product_id');
            })
            ->join('orders as o', 'o.id', '=', 'a.order_id')
            // Only products that still exist (the projection's FKs), never a deleted one.
            ->join('products as pa', 'pa.id', '=', 'a.product_id')
            ->join('products as pb', 'pb.id', '=', 'b.product_id')
            ->where('o.status', '<>', 'cancelled')
            ->whereRaw("{$placedAt} >= ?", [$since->toDateTimeString()])
            ->whereRaw("{$placedAt} <= ?", [$now->toDateTimeString()])
            ->groupBy('a.product_id', 'b.product_id')
            ->orderBy('a.product_id')
            ->orderBy('b.product_id')
            ->selectRaw("a.product_id as product_id, b.product_id as other_product_id, COUNT(DISTINCT a.order_id) as orders_count, MAX({$placedAt}) as last_ordered_at")
            ->get();

        $computedAt = $now->toDateTimeString();
        $rows = $pairs->map(static fn (object $r): array => [
            'product_id' => (int) $r->product_id,
            'other_product_id' => (int) $r->other_product_id,
            'orders_count' => (int) $r->orders_count,
            'last_ordered_at' => CarbonImmutable::parse((string) $r->last_ordered_at)->toDateTimeString(),
            'computed_at' => $computedAt,
        ])->all();

        DB::transaction(static function () use ($rows): void {
            DB::table('product_copurchases')->delete();
            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                DB::table('product_copurchases')->insert($chunk);
            }
        });

        ProductCopurchasesRebuilt::dispatch(count($rows));

        return ['pairs' => count($rows), 'since' => $since, 'computed_at' => $now];
    }
}
