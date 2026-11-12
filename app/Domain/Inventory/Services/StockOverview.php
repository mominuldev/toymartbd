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
 *   out   available ≤ 0
 *   low   0 < available ≤ reorder_point
 *   idle  on_hand > 0 and no movement at this location for IDLE_DAYS days
 */
final class StockOverview
{
    public const string LOW = 'low';

    public const string OUT = 'out';

    public const string IDLE = 'idle';

    /** @var list<string> */
    public const array STATES = [self::LOW, self::OUT, self::IDLE];

    public const int IDLE_DAYS = 90;

    public const string AVAILABLE_SQL = '(COALESCE(stock_levels.on_hand, 0) - COALESCE(stock_levels.reserved, 0))';

    /**
     * Variant rows at a location, with `on_hand`, `reserved`, `reorder_point` and
     * `avg_cost` selected alongside the variant's own columns. Ordered for stable cursor
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
            ->selectRaw('COALESCE(stock_levels.avg_cost, 0) as avg_cost');
    }

    /** @param  Builder<ProductVariant>  $query */
    public function whereState(Builder $query, string $state, int $locationId): void
    {
        $available = self::AVAILABLE_SQL;

        match ($state) {
            self::OUT => $query->whereRaw("{$available} <= 0"),
            self::LOW => $query->whereRaw("{$available} > 0")->whereRaw("{$available} <= COALESCE(stock_levels.reorder_point, 0)"),
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

    /** The row's state at its location: out, low, or null when neither. */
    public static function stateOf(int $onHand, int $reserved, int $reorderPoint): ?string
    {
        $available = $onHand - $reserved;

        return match (true) {
            $available <= 0 => self::OUT,
            $available <= $reorderPoint => self::LOW,
            default => null,
        };
    }

    /**
     * Stat cards, location cards and tab counts for one location.
     *
     * @return array{
     *     location_id: int,
     *     counts: array{all: int, low: int, out: int, idle: int},
     *     on_hand: int,
     *     reserved: int,
     *     value: string,
     *     total_value: string,
     *     locations: list<array{id: int, code: string, name: string, name_bn: string|null, is_default: bool, on_hand: int, value: string}>
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
                'is_default' => $location->is_default,
                'on_hand' => $total['on_hand'],
                'value' => $total['value'],
            ];
        }
        $here = $totals[$locationId] ?? ['on_hand' => 0, 'reserved' => 0, 'value' => '0.00'];

        return [
            'location_id' => $locationId,
            'counts' => ['all' => $counts['all'], 'low' => $counts[self::LOW], 'out' => $counts[self::OUT], 'idle' => $counts[self::IDLE]],
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
     * On hand at every OTHER active location, for the "Other branches" column.
     *
     * @param  list<int>  $variantIds
     * @return array<int, list<array{location_id: int, code: string, on_hand: int}>>
     */
    public function elsewhere(array $variantIds, int $locationId): array
    {
        if ($variantIds === []) {
            return [];
        }

        $out = [];
        $rows = DB::table('stock_levels')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')
            ->whereIn('stock_levels.variant_id', $variantIds)
            ->where('stock_levels.location_id', '!=', $locationId)
            ->where('stock_locations.is_active', true)
            ->orderBy('stock_locations.id')
            ->get(['stock_levels.variant_id', 'stock_levels.location_id', 'stock_locations.code', 'stock_levels.on_hand']);

        foreach ($rows as $row) {
            $out[(int) $row->variant_id][] = ['location_id' => (int) $row->location_id, 'code' => (string) $row->code, 'on_hand' => (int) $row->on_hand];
        }

        return $out;
    }
}
