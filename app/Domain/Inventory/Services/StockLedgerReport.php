<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Read model for the stock movement ledger screen: the append-only movements with who
 * made them and what document they belong to, and the reconciliation strip — opening,
 * in, out and closing units for the period, plus the §7.1 invariant check
 * (SUM(stock_movements.quantity) = stock_levels.on_hand for every variant × location).
 *
 * The invariant check reports drift; it never repairs it. Drift means a code path wrote
 * stock outside StockLedger, and silently correcting it would hide that bug
 * (DATABASE-DESIGN §7).
 */
final readonly class StockLedgerReport
{
    /** How many drifting variant ids the reconciliation lists (the count is always exact). */
    public const int DRIFT_SAMPLE = 50;

    public function __construct(private StockOverview $overview) {}

    /**
     * Movements newest first, with `user_name`, `reference_number` (an adjustment's
     * ADJ-… or a transfer's TRF-… number) and `adjustment_reason` selected alongside.
     * Filter by adjustment reason on `stock_adjustments.reason`.
     *
     * @return Builder<StockMovement>
     */
    public function movements(): Builder
    {
        return StockMovement::query()
            ->leftJoin('users', 'users.id', '=', 'stock_movements.user_id')
            ->leftJoin('stock_adjustments', static function (JoinClause $join): void {
                $join->on('stock_adjustments.id', '=', 'stock_movements.reference_id')
                    ->where('stock_movements.reference_type', '=', StockAdjustment::REFERENCE_TYPE);
            })
            ->leftJoin('stock_transfers', static function (JoinClause $join): void {
                $join->on('stock_transfers.id', '=', 'stock_movements.reference_id')
                    ->where('stock_movements.reference_type', '=', StockTransfer::REFERENCE_TYPE);
            })
            ->select('stock_movements.*', 'users.name as user_name', 'stock_adjustments.reason as adjustment_reason')
            ->selectRaw('COALESCE(stock_adjustments.number, stock_transfers.number) as reference_number');
    }

    /**
     * @param  array{location_id?: int|null, variant_id?: int|null, product_id?: int|null, from?: CarbonImmutable|null, to?: CarbonImmutable|null}  $scope
     *                                                                                                                                                      `from` inclusive, `to` exclusive (UTC instants)
     * @return array{opening: int, in: int, out: int, closing: int, variants_checked: int, drift_count: int, drift_variant_ids: list<int>, balanced: bool, stock_value: string}
     */
    public function reconciliation(array $scope): array
    {
        $scoped = fn (QueryBuilder $q, string $table): QueryBuilder => $q
            ->when($scope['location_id'] ?? null, static fn ($q, int $id) => $q->where("{$table}.location_id", $id))
            ->when($scope['variant_id'] ?? null, static fn ($q, int $id) => $q->where("{$table}.variant_id", $id))
            ->when($scope['product_id'] ?? null, static fn ($q, int $id) => $q->whereIn("{$table}.variant_id", DB::table('product_variants')->where('product_id', $id)->select('id')));

        $movements = static fn (): QueryBuilder => DB::table('stock_movements');
        $from = $scope['from'] ?? null;
        $to = $scope['to'] ?? null;

        $opening = $from === null ? 0 : (int) $scoped($movements(), 'stock_movements')->where('created_at', '<', $from)->sum('quantity');
        $period = static fn () => $scoped($movements(), 'stock_movements')
            ->when($from, static fn ($q, $at) => $q->where('created_at', '>=', $at))
            ->when($to, static fn ($q, $at) => $q->where('created_at', '<', $at));
        $in = (int) $period()->where('quantity', '>', 0)->sum('quantity');
        $out = (int) $period()->where('quantity', '<', 0)->sum('quantity');

        // §7.1: per (variant, location), the ledger sum must equal the projection.
        $sums = $scoped($movements(), 'stock_movements')
            ->select('variant_id', 'location_id')->selectRaw('SUM(quantity) as total')
            ->groupBy('variant_id', 'location_id');
        $levelDrift = $scoped(DB::table('stock_levels'), 'stock_levels')
            ->leftJoinSub($sums, 'ledger', static function (JoinClause $join): void {
                $join->on('ledger.variant_id', '=', 'stock_levels.variant_id')->on('ledger.location_id', '=', 'stock_levels.location_id');
            })
            ->whereRaw('COALESCE(ledger.total, 0) <> stock_levels.on_hand')
            ->distinct()->pluck('stock_levels.variant_id');
        $orphanDrift = DB::query()->fromSub($sums, 'ledger')
            ->leftJoin('stock_levels', static function (JoinClause $join): void {
                $join->on('stock_levels.variant_id', '=', 'ledger.variant_id')->on('stock_levels.location_id', '=', 'ledger.location_id');
            })
            ->whereNull('stock_levels.id')->where('ledger.total', '<>', 0)
            ->distinct()->pluck('ledger.variant_id');
        $drift = $levelDrift->merge($orphanDrift)->map(static fn (mixed $id): int => (int) $id)->unique()->sort()->values();

        $variantIds = isset($scope['variant_id']) ? [$scope['variant_id']]
            : (isset($scope['product_id']) ? DB::table('product_variants')->where('product_id', $scope['product_id'])->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all() : null);
        $value = '0.00';
        foreach ($this->overview->totalsByLocation($variantIds) as $locationId => $totals) {
            if (! isset($scope['location_id']) || $scope['location_id'] === $locationId) {
                $value = StockValuation::add($value, $totals['value']);
            }
        }

        return [
            'opening' => $opening,
            'in' => $in,
            'out' => $out,
            'closing' => $opening + $in + $out,
            'variants_checked' => $scoped(DB::table('stock_levels'), 'stock_levels')->distinct()->count('stock_levels.variant_id'),
            'drift_count' => $drift->count(),
            'drift_variant_ids' => $drift->take(self::DRIFT_SAMPLE)->all(),
            'balanced' => $drift->isEmpty(),
            'stock_value' => $value,
        ];
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_map(static fn (StockMovementType $t): string => $t->value, StockMovementType::cases());
    }
}
