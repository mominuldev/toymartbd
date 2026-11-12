<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read model for the admin inventory overview (06-admin-feature-checklist §2.7): one row
 * per variant at a location with on hand / reserved / available, the low, out-of-stock
 * and idle filters, and valuation at cost. Read-only — never use it for a stock
 * decision; the write paths lock the rows themselves.
 *
 * A row is every sellable variant (active variant of a product that is not archived)
 * plus any variant that still holds or reserves stock, so archiving a product never
 * hides units that are physically on the shelf. A variant without a stock_levels row
 * reads as zeros.
 *
 * States, on `available = on_hand - reserved` at the location:
 *   out        available ≤ 0
 *   low        0 < available ≤ reorder_point
 *   overstock  reorder_quantity > 0 and available > reorder_point + 2 × reorder_quantity
 *              (owner decision pending, DATABASE-DESIGN §3.2: more than two full reorders
 *              above the reorder point is cash sitting on the shelf; a level without a
 *              reorder quantity is never overstock)
 *   idle       on_hand > 0 and no movement at this location for IDLE_DAYS days
 */
final class StockOverview
{
    public const string LOW = 'low';

    public const string OUT = 'out';

    public const string OVERSTOCK = 'overstock';

    public const string IDLE = 'idle';

    /** @var list<string> */
    public const array STATES = [self::LOW, self::OUT, self::OVERSTOCK, self::IDLE];

    /** Overstock is more than this many reorder quantities above the reorder point. */
    public const int OVERSTOCK_REORDERS = 2;

    public const int IDLE_DAYS = 90;

    /** Seconds the nav badge's low-stock count may be cached at most. */
    public const int LOW_COUNT_TTL = 300;

    public const string AVAILABLE_SQL = '(COALESCE(stock_levels.on_hand, 0) - COALESCE(stock_levels.reserved, 0))';

    /**
     * Variant rows at a location, with `on_hand`, `reserved`, `reorder_point`,
     * `reorder_quantity` and `avg_cost` selected alongside the variant's own columns. Ordered for stable cursor
     * pagination by the caller (product id, then variant id).
     *
     * @return Builder<ProductVariant>
     */
    public function rows(int $locationId): Builder
    {
        return ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('stock_levels', static function (JoinClause $join) use ($locationId): void {
                $join->on('stock_levels.variant_id', '=', 'product_variants.id')->where('stock_levels.location_id', '=', $locationId);
            })
            ->where(static function (Builder $q): void {
                $q->where(static function (Builder $sellable): void {
                    $sellable->where('product_variants.status', VariantStatus::Active->value)
                        ->where('products.status', '!=', ProductStatus::Archived->value);
                })
                    ->orWhere('stock_levels.on_hand', '!=', 0)
                    ->orWhere('stock_levels.reserved', '!=', 0);
            })
            ->select('product_variants.*')
            ->selectRaw('COALESCE(stock_levels.on_hand, 0) as on_hand')
            ->selectRaw('COALESCE(stock_levels.reserved, 0) as reserved')
            ->selectRaw('COALESCE(stock_levels.reorder_point, 0) as reorder_point')
            ->selectRaw('COALESCE(stock_levels.reorder_quantity, 0) as reorder_quantity')
            ->selectRaw('COALESCE(stock_levels.avg_cost, 0) as avg_cost');
    }

    /** @param  Builder<ProductVariant>  $query */
    public function whereState(Builder $query, string $state, int $locationId): void
    {
        $available = self::AVAILABLE_SQL;

        match ($state) {
            self::OUT => $query->whereRaw("{$available} <= 0"),
            self::LOW => $query->whereRaw("{$available} > 0")->whereRaw("{$available} <= COALESCE(stock_levels.reorder_point, 0)"),
            self::OVERSTOCK => $query->where('stock_levels.reorder_quantity', '>', 0)
                ->whereRaw("{$available} > stock_levels.reorder_point + ? * stock_levels.reorder_quantity", [self::OVERSTOCK_REORDERS]),
            self::IDLE => $query->where('stock_levels.on_hand', '>', 0)->whereNotExists(static function ($sub) use ($locationId): void {
                $sub->from('stock_movements')
                    ->whereColumn('stock_movements.variant_id', 'product_variants.id')
                    ->where('stock_movements.location_id', $locationId)
                    ->where('stock_movements.created_at', '>=', Carbon::now()->subDays(self::IDLE_DAYS));
            }),
            default => null,
        };
    }

    /** @param  Builder<ProductVariant>  $query */
    public function whereSearch(Builder $query, string $term): void
    {
        $like = '%'.addcslashes($term, '\\%_').'%';

        $query->where(static function (Builder $q) use ($like): void {
            $q->where('products.name', 'like', $like)
                ->orWhere('products.name_bn', 'like', $like)
                ->orWhere('product_variants.sku', 'like', $like)
                ->orWhere('product_variants.barcode', 'like', $like);
        });
    }

    /** The row's state at its location: out, low, overstock, or null when none applies. */
    public static function stateOf(int $onHand, int $reserved, int $reorderPoint, int $reorderQuantity = 0): ?string
    {
        $available = $onHand - $reserved;

        return match (true) {
            $available <= 0 => self::OUT,
            $available <= $reorderPoint => self::LOW,
            $reorderQuantity > 0 && $available > $reorderPoint + self::OVERSTOCK_REORDERS * $reorderQuantity => self::OVERSTOCK,
            default => null,
        };
    }

    /**
     * Low-stock variants at a location (the default one when null) — the Inventory nav
     * badge (Figma SidebarItem CountBadge, B · 14 Inventory). The Low stock tab's rule,
     * one COUNT, cached until a stock level or a reorder point changes
     * (ForgetLowStockCount, SetReorderLevels) and for at most LOW_COUNT_TTL seconds.
     */
    public function lowCount(?int $locationId = null): int
    {
        $locationId ??= StockLocation::defaultId();

        return (int) Cache::remember(self::lowCountKey($locationId), self::LOW_COUNT_TTL, function () use ($locationId): int {
            $query = $this->rows($locationId);
            $this->whereState($query, self::LOW, $locationId);

            return $query->count();
        });
    }

    /** Drop every location's cached low-stock count. */
    public static function forgetLowCounts(): void
    {
        foreach (StockLocation::query()->pluck('id') as $id) {
            Cache::forget(self::lowCountKey((int) $id));
        }
    }

    private static function lowCountKey(int $locationId): string
    {
        return 'inventory:low-stock-count:'.$locationId;
    }

    /**
     * Stat cards, location cards and tab counts for one location.
     *
     * @return array{
     *     location_id: int,
     *     counts: array{all: int, low: int, out: int, overstock: int, idle: int},
     *     on_hand: int,
     *     reserved: int,
     *     value: string,
     *     total_value: string,
     *     locations: list<array{id: int, code: string, name: string, name_bn: string|null, description: string|null, description_bn: string|null, is_default: bool, on_hand: int, value: string}>
     * }
     */
    public function summary(int $locationId): array
    {
        $counts = ['all' => $this->rows($locationId)->count()];
        foreach (self::STATES as $state) {
            $query = $this->rows($locationId);
            $this->whereState($query, $state, $locationId);
            $counts[$state] = $query->count();
        }

        $totals = $this->totalsByLocation();
        $locations = [];
        $totalValue = '0.00';
        foreach (StockLocation::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->get() as $location) {
            $total = $totals[$location->id] ?? ['on_hand' => 0, 'reserved' => 0, 'value' => '0.00'];
            $totalValue = StockValuation::add($totalValue, $total['value']);
            $locations[] = [
                'id' => $location->id,
                'code' => $location->code,
                'name' => $location->name,
                'name_bn' => $location->name_bn,
                'description' => $location->description,
                'description_bn' => $location->description_bn,
                'is_default' => $location->is_default,
                'on_hand' => $total['on_hand'],
                'value' => $total['value'],
            ];
        }
        $here = $totals[$locationId] ?? ['on_hand' => 0, 'reserved' => 0, 'value' => '0.00'];

        return [
            'location_id' => $locationId,
            'counts' => ['all' => $counts['all'], 'low' => $counts[self::LOW], 'out' => $counts[self::OUT], 'overstock' => $counts[self::OVERSTOCK], 'idle' => $counts[self::IDLE]],
            'on_hand' => $here['on_hand'],
            'reserved' => $here['reserved'],
            'value' => $here['value'],
            'total_value' => $totalValue,
            'locations' => $locations,
        ];
    }

    /**
     * Units and value at cost per location, from every level row that holds stock.
     * Summed in PHP with bcmath so no float ever touches money (rule 6).
     *
     * @param  list<int>|null  $variantIds  limit to these variants
     * @return array<int, array{on_hand: int, reserved: int, value: string}>
     */
    public function totalsByLocation(?array $variantIds = null): array
    {
        $totals = [];

        DB::table('stock_levels')
            ->join('product_variants', 'product_variants.id', '=', 'stock_levels.variant_id')
            ->where(static fn ($q) => $q->where('stock_levels.on_hand', '!=', 0)->orWhere('stock_levels.reserved', '!=', 0))
            ->when($variantIds !== null, static fn ($q) => $q->whereIn('stock_levels.variant_id', $variantIds))
            ->select('stock_levels.id', 'stock_levels.location_id', 'stock_levels.on_hand', 'stock_levels.reserved', 'stock_levels.avg_cost', 'product_variants.cost_price')
            ->orderBy('stock_levels.id')
            ->chunk(1000, static function ($rows) use (&$totals): void {
                foreach ($rows as $row) {
                    $locationId = (int) $row->location_id;
                    $totals[$locationId] ??= ['on_hand' => 0, 'reserved' => 0, 'value' => '0.00'];
                    $totals[$locationId]['on_hand'] += (int) $row->on_hand;
                    $totals[$locationId]['reserved'] += (int) $row->reserved;
                    $totals[$locationId]['value'] = StockValuation::add(
                        $totals[$locationId]['value'],
                        StockValuation::value((int) $row->on_hand, StockValuation::costBasis($row->avg_cost, $row->cost_price)),
                    );
                }
            });

        return $totals;
    }

    /**
     * On hand and available at every OTHER active location, for the "Other branches"
     * column: one entry per location in location order, zeros where it holds no level
     * row, so "CTG 4 · SYL 0" always names every other branch.
     *
     * @param  list<int>  $variantIds
     * @return array<int, list<array{location_id: int, code: string, on_hand: int, available: int}>>
     */
    public function elsewhere(array $variantIds, int $locationId): array
    {
        if ($variantIds === []) {
            return [];
        }

        $others = StockLocation::query()->where('is_active', true)->whereKeyNot($locationId)->orderByDesc('is_default')->orderBy('id')->get(['id', 'code']);
        if ($others->isEmpty()) {
            return [];
        }

        $levels = [];
        DB::table('stock_levels')
            ->whereIn('variant_id', $variantIds)
            ->whereIn('location_id', $others->pluck('id')->all())
            ->get(['variant_id', 'location_id', 'on_hand', 'reserved'])
            ->each(static function (object $row) use (&$levels): void {
                $levels[(int) $row->variant_id][(int) $row->location_id] = [(int) $row->on_hand, (int) $row->reserved];
            });

        $out = [];
        foreach ($variantIds as $variantId) {
            foreach ($others as $location) {
                [$onHand, $reserved] = $levels[$variantId][$location->id] ?? [0, 0];
                $out[$variantId][] = ['location_id' => $location->id, 'code' => $location->code, 'on_hand' => $onHand, 'available' => $onHand - $reserved];
            }
        }

        return $out;
    }
}
