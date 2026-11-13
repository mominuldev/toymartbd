<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Reports;

use App\Domain\Inventory\Actions\ReceivePurchasedStock;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Services\StockValuation;
use App\Support\DhakaDate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Inventory valuation at cost (P5, 06-admin-feature-checklist §2.7). Read-only: it never
 * writes stock_levels or stock_movements.
 *
 * **Now:** on_hand × the location's cost basis per variant × location — the projection's
 * weighted-average cost (`stock_levels.avg_cost`), else the variant's current cost price
 * (StockValuation, the same rule as the inventory overview, so the two screens agree).
 *
 * **As at a past Dhaka date:** units are exact — Σ stock_movements.quantity up to the end
 * of that day (rule 8: the ledger is the truth). The unit cost is *replayed* from the
 * movements' recorded `unit_cost`: the first costed movement sets the basis and every
 * later purchase_in / transfer_in / opening_balance blends in with the receipt's own
 * weighted-average step (ReceivePurchasedStock::nextAverage). A variant whose movements
 * carry no cost falls back to today's cost basis, and is flagged `estimated`.
 *
 * Totals by location and by primary category are bcmath sums of the per-row values, so
 * they add up to the grand total to the poisha.
 */
final readonly class InventoryValuationReport
{
    public const array SORTS = ['value', 'on_hand', 'name', 'unit_cost'];

    public const int MAX_ROWS = 1000;

    /** In-movements whose unit_cost moves the weighted average. */
    private const array COSTED_IN = [StockMovementType::PurchaseIn, StockMovementType::TransferIn, StockMovementType::OpeningBalance];

    /**
     * @param  array{location_id?: int|null, category_id?: int|null, search?: string|null}  $filters
     * @param  string|null  $asAt  a Dhaka date (Y-m-d); null = now
     * @return array{as_at: string|null, rows: list<array<string, mixed>>, totals: array{on_hand: int, value: string, rows: int, estimated_rows: int}, by_location: list<array<string, mixed>>, by_category: list<array<string, mixed>>, truncated: bool}
     */
    public function report(array $filters = [], ?string $asAt = null, string $sort = '-value'): array
    {
        $rows = $asAt === null ? $this->current($filters) : $this->replayed($filters, $asAt);

        $total = '0.00';
        $units = 0;
        $estimated = 0;
        $locations = [];
        $categories = [];
        foreach ($rows as $row) {
            $total = StockValuation::add($total, $row['value']);
            $units += $row['on_hand'];
            $estimated += $row['estimated'] ? 1 : 0;

            $l = $row['location_id'];
            $locations[$l] ??= ['location_id' => $l, 'code' => $row['location_code'], 'name' => $row['location_name'], 'name_bn' => $row['location_name_bn'], 'on_hand' => 0, 'value' => '0.00', 'variants' => 0];
            $locations[$l]['on_hand'] += $row['on_hand'];
            $locations[$l]['value'] = StockValuation::add($locations[$l]['value'], $row['value']);
            $locations[$l]['variants']++;

            $c = $row['category_id'] ?? 0;
            $categories[$c] ??= ['category_id' => $row['category_id'], 'name' => $row['category_name'], 'name_bn' => $row['category_name_bn'], 'on_hand' => 0, 'value' => '0.00', 'variants' => 0];
            $categories[$c]['on_hand'] += $row['on_hand'];
            $categories[$c]['value'] = StockValuation::add($categories[$c]['value'], $row['value']);
            $categories[$c]['variants']++;
        }

        $byValue = static fn (array $a, array $b): int => bccomp($b['value'], $a['value'], 2) ?: strcmp((string) $a['name'], (string) $b['name']);
        $locations = array_values($locations);
        $categories = array_values($categories);
        usort($locations, $byValue);
        usort($categories, $byValue);

        $rows = self::sort($rows, $sort);

        return [
            'as_at' => $asAt,
            'rows' => array_slice($rows, 0, self::MAX_ROWS),
            'totals' => ['on_hand' => $units, 'value' => $total, 'rows' => count($rows), 'estimated_rows' => $estimated],
            'by_location' => $locations,
            'by_category' => $categories,
            'truncated' => count($rows) > self::MAX_ROWS,
        ];
    }

    /**
     * @param  array{location_id?: int|null, category_id?: int|null, search?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    private function current(array $filters): array
    {
        $query = $this->base($filters)
            ->join('stock_levels', 'stock_levels.variant_id', '=', 'product_variants.id')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')
            ->where('stock_levels.on_hand', '!=', 0)
            ->when($filters['location_id'] ?? null, static fn (Builder $q, int $id) => $q->where('stock_levels.location_id', $id))
            ->addSelect(['stock_levels.location_id', 'stock_levels.on_hand', 'stock_levels.avg_cost', 'stock_locations.code as location_code', 'stock_locations.name as location_name', 'stock_locations.name_bn as location_name_bn'])
            ->orderBy('product_variants.id')
            ->orderBy('stock_levels.location_id');

        $rows = [];
        foreach ($query->cursor() as $r) {
            $unit = StockValuation::costBasis(self::scalar($r->avg_cost), self::scalar($r->cost_price));
            $rows[] = self::row($r, (int) $r->on_hand, $unit, estimated: false);
        }

        return $rows;
    }

    /**
     * @param  array{location_id?: int|null, category_id?: int|null, search?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    private function replayed(array $filters, string $asAt): array
    {
        $end = DhakaDate::utcBounds($asAt)[1];

        $variants = $this->base($filters)->get()->keyBy('variant_id');
        $locations = DB::table('stock_locations')->get(['id', 'code', 'name', 'name_bn'])->keyBy('id');
        $levels = DB::table('stock_levels')->get(['variant_id', 'location_id', 'avg_cost']);
        $currentAvg = [];
        foreach ($levels as $level) {
            $currentAvg[$level->variant_id.':'.$level->location_id] = self::scalar($level->avg_cost);
        }

        $state = [];
        $movements = DB::table('stock_movements')
            ->where('created_at', '<', $end)
            ->whereIn('variant_id', $variants->keys()->all() ?: [0])
            ->when($filters['location_id'] ?? null, static fn (Builder $q, int $id) => $q->where('location_id', $id))
            ->orderBy('id')
            ->select(['id', 'variant_id', 'location_id', 'type', 'quantity', 'unit_cost']);

        $costed = array_map(static fn (StockMovementType $t): string => $t->value, self::COSTED_IN);
        foreach ($movements->lazyById(2000) as $m) {
            $key = $m->variant_id.':'.$m->location_id;
            $state[$key] ??= ['variant_id' => (int) $m->variant_id, 'location_id' => (int) $m->location_id, 'on_hand' => 0, 'basis' => null];
            $quantity = (int) $m->quantity;
            $cost = self::scalar($m->unit_cost);
            if ($quantity > 0 && $cost !== null && in_array($m->type, $costed, true)) {
                $state[$key]['basis'] = $state[$key]['basis'] === null
                    ? bcadd($cost, '0', 4)
                    : ReceivePurchasedStock::nextAverage($state[$key]['on_hand'], $state[$key]['basis'], $quantity, $cost);
            }
            $state[$key]['on_hand'] += $quantity;
        }

        ksort($state);
        $rows = [];
        foreach ($state as $key => $s) {
            if ($s['on_hand'] === 0) {
                continue;
            }
            $v = $variants[$s['variant_id']];
            $location = $locations[$s['location_id']] ?? null;
            $estimated = $s['basis'] === null;
            $unit = $s['basis'] ?? StockValuation::costBasis($currentAvg[$key] ?? null, self::scalar($v->cost_price));
            $v->location_id = $s['location_id'];
            $v->location_code = $location->code ?? '';
            $v->location_name = $location->name ?? '';
            $v->location_name_bn = $location->name_bn ?? null;
            $rows[] = self::row($v, $s['on_hand'], $unit, $estimated);
        }

        return $rows;
    }

    /** @param  array{location_id?: int|null, category_id?: int|null, search?: string|null}  $filters */
    private function base(array $filters): Builder
    {
        $query = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.primary_category_id')
            ->select([
                'product_variants.id as variant_id', 'product_variants.sku', 'product_variants.name as variant_name', 'product_variants.cost_price',
                'products.id as product_id', 'products.name as product_name', 'products.name_bn as product_name_bn',
                'products.primary_category_id as category_id', 'categories.name as category_name', 'categories.name_bn as category_name_bn',
            ]);

        if (($categoryId = $filters['category_id'] ?? null) !== null) {
            $bounds = DB::table('categories')->where('id', $categoryId)->first(['_lft', '_rgt']);
            $query->whereIn('products.primary_category_id', DB::table('categories')
                ->where('_lft', '>=', (int) ($bounds->_lft ?? 0))
                ->where('_rgt', '<=', (int) ($bounds->_rgt ?? -1))
                ->select('id'));
        }
        if (($search = $filters['search'] ?? null) !== null) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(static fn (Builder $q) => $q
                ->where('products.name', 'like', $like)
                ->orWhere('products.name_bn', 'like', $like)
                ->orWhere('product_variants.sku', 'like', $like));
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private static function row(object $r, int $onHand, string $unit, bool $estimated): array
    {
        return [
            'variant_id' => (int) $r->variant_id,
            'product_id' => (int) $r->product_id,
            'sku' => (string) $r->sku,
            'name' => (string) $r->product_name,
            'name_bn' => $r->product_name_bn,
            'variant_name' => $r->variant_name,
            'category_id' => $r->category_id === null ? null : (int) $r->category_id,
            'category_name' => $r->category_name,
            'category_name_bn' => $r->category_name_bn,
            'location_id' => (int) $r->location_id,
            'location_code' => (string) $r->location_code,
            'location_name' => (string) $r->location_name,
            'location_name_bn' => $r->location_name_bn,
            'on_hand' => $onHand,
            'unit_cost' => $unit,
            'value' => StockValuation::value($onHand, $unit),
            'estimated' => $estimated,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sort(array $rows, string $sort): array
    {
        $descending = str_starts_with($sort, '-');
        $field = ltrim($sort, '-');
        usort($rows, static function (array $a, array $b) use ($field, $descending): int {
            $cmp = match ($field) {
                'name' => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])),
                'on_hand' => $a['on_hand'] <=> $b['on_hand'],
                'unit_cost' => bccomp($a['unit_cost'], $b['unit_cost'], 4),
                default => bccomp($a['value'], $b['value'], 2),
            };
            $cmp = $descending ? -$cmp : $cmp;

            return $cmp !== 0 ? $cmp : ($a['variant_id'] <=> $b['variant_id'] ?: $a['location_id'] <=> $b['location_id']);
        });

        return $rows;
    }

    private static function scalar(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_float($value) ? sprintf('%.4F', $value) : (is_scalar($value) ? (string) $value : null);
    }
}
