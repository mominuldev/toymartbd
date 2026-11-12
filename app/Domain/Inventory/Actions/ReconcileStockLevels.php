<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Contracts\OpenOrderReservations;
use App\Domain\Inventory\Enums\InventoryAlertKind;
use App\Domain\Inventory\Enums\InventoryAlertStatus;
use App\Domain\Inventory\Events\StockDriftDetected;
use App\Domain\Inventory\Models\InventoryAlert;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The nightly stock reconciliation (DATABASE-DESIGN §7, P5-INV). Recomputes every
 * level from the append-only ledger and compares:
 *
 *   1. SUM(stock_movements.quantity) = stock_levels.on_hand, per (variant, location),
 *      including ledger sums with no level row at all;
 *   5. stock_levels.reserved = the open-order quantity — only when Ordering has bound
 *      OpenOrderReservations (otherwise skipped and reported as such).
 *
 * It NEVER corrects a level. Drift means a code path bypassed a transaction, and a
 * silent fix would hide the bug: each drift becomes (or refreshes) an open
 * `inventory_alerts` row a person must acknowledge, is logged as a warning, and — when
 * new or changed since the last run — is announced (StockDriftDetected) so the managers
 * are mailed. A drift already reported with the same figures is only re-stamped.
 */
final readonly class ReconcileStockLevels
{
    public function __construct(private Container $container) {}

    /**
     * @return array{checked: int, drift_count: int, new_or_changed: int, reserved_checked: bool}
     */
    public function handle(): array
    {
        $now = CarbonImmutable::now();
        $drifts = $this->ledgerDrift();
        $reservedChecked = $this->container->bound(OpenOrderReservations::class);
        if ($reservedChecked) {
            $drifts = array_merge($drifts, $this->reservedDrift());
        }

        $announce = DB::transaction(function () use ($drifts, $now): array {
            $announce = [];
            foreach ($drifts as $drift) {
                $open = InventoryAlert::query()->open()
                    ->where('kind', $drift['kind']->value)
                    ->where('variant_id', $drift['variant_id'])
                    ->where('location_id', $drift['location_id'])
                    ->lockForUpdate()
                    ->first();

                if ($open !== null && $open->expected === $drift['expected'] && $open->actual === $drift['actual']) {
                    $open->fill(['last_seen_at' => $now])->save();

                    continue;
                }
                if ($open !== null) {
                    $open->fill(['expected' => $drift['expected'], 'actual' => $drift['actual'], 'last_seen_at' => $now])->save();
                    $alert = $open;
                } else {
                    $alert = InventoryAlert::query()->create([
                        'kind' => $drift['kind'],
                        'status' => InventoryAlertStatus::Open,
                        'variant_id' => $drift['variant_id'],
                        'location_id' => $drift['location_id'],
                        'expected' => $drift['expected'],
                        'actual' => $drift['actual'],
                        'alert_date' => DhakaDate::dayOf($now),
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                    ]);
                }
                $announce[] = $alert->id;
            }

            return $announce;
        });

        foreach ($drifts as $drift) {
            Log::warning('Stock reconciliation drift', [
                'kind' => $drift['kind']->value,
                'variant_id' => $drift['variant_id'],
                'location_id' => $drift['location_id'],
                'expected' => $drift['expected'],
                'actual' => $drift['actual'],
            ]);
        }

        if ($announce !== []) {
            StockDriftDetected::dispatch($now, $this->describe($announce), InventoryAlert::query()->open()
                ->whereIn('kind', array_map(static fn (InventoryAlertKind $k): string => $k->value, InventoryAlertKind::driftKinds()))->count());
        }

        return [
            'checked' => DB::table('stock_levels')->count(),
            'drift_count' => count($drifts),
            'new_or_changed' => count($announce),
            'reserved_checked' => $reservedChecked,
        ];
    }

    /** @return list<array{kind: InventoryAlertKind, variant_id: int, location_id: int, expected: int, actual: int}> */
    private function ledgerDrift(): array
    {
        $sums = DB::table('stock_movements')
            ->select('variant_id', 'location_id')->selectRaw('SUM(quantity) as total')
            ->groupBy('variant_id', 'location_id');

        $rows = DB::table('stock_levels')
            ->leftJoinSub($sums, 'ledger', static function (JoinClause $join): void {
                $join->on('ledger.variant_id', '=', 'stock_levels.variant_id')->on('ledger.location_id', '=', 'stock_levels.location_id');
            })
            ->whereRaw('COALESCE(ledger.total, 0) <> stock_levels.on_hand')
            ->get(['stock_levels.variant_id', 'stock_levels.location_id', 'stock_levels.on_hand', DB::raw('COALESCE(ledger.total, 0) as total')])
            ->map(static fn (object $r): array => ['variant_id' => (int) $r->variant_id, 'location_id' => (int) $r->location_id, 'expected' => (int) $r->total, 'actual' => (int) $r->on_hand]);

        $orphans = DB::query()->fromSub($sums, 'ledger')
            ->leftJoin('stock_levels', static function (JoinClause $join): void {
                $join->on('stock_levels.variant_id', '=', 'ledger.variant_id')->on('stock_levels.location_id', '=', 'ledger.location_id');
            })
            ->whereNull('stock_levels.id')->where('ledger.total', '<>', 0)
            ->get(['ledger.variant_id', 'ledger.location_id', 'ledger.total'])
            ->map(static fn (object $r): array => ['variant_id' => (int) $r->variant_id, 'location_id' => (int) $r->location_id, 'expected' => (int) $r->total, 'actual' => 0]);

        return $rows->merge($orphans)
            ->map(static fn (array $r): array => ['kind' => InventoryAlertKind::LedgerDrift] + $r)
            ->sortBy([['variant_id', 'asc'], ['location_id', 'asc']])
            ->values()->all();
    }

    /** @return list<array{kind: InventoryAlertKind, variant_id: int, location_id: int, expected: int, actual: int}> */
    private function reservedDrift(): array
    {
        $expected = $this->container->make(OpenOrderReservations::class)->quantities();
        $drift = [];

        foreach (DB::table('stock_levels')->orderBy('variant_id')->orderBy('location_id')->get(['variant_id', 'location_id', 'reserved']) as $level) {
            $want = $expected[(int) $level->location_id][(int) $level->variant_id] ?? 0;
            unset($expected[(int) $level->location_id][(int) $level->variant_id]);
            if ($want !== (int) $level->reserved) {
                $drift[] = ['kind' => InventoryAlertKind::ReservedDrift, 'variant_id' => (int) $level->variant_id, 'location_id' => (int) $level->location_id, 'expected' => $want, 'actual' => (int) $level->reserved];
            }
        }
        foreach ($expected as $locationId => $variants) {
            foreach ($variants as $variantId => $want) {
                if ($want !== 0) {
                    $drift[] = ['kind' => InventoryAlertKind::ReservedDrift, 'variant_id' => $variantId, 'location_id' => $locationId, 'expected' => $want, 'actual' => 0];
                }
            }
        }

        return $drift;
    }

    /**
     * @param  list<int>  $alertIds
     * @return list<array{alert_id: int, kind: string, variant_id: int, sku: string, product_name: string, location_code: string, expected: int, actual: int}>
     */
    private function describe(array $alertIds): array
    {
        return InventoryAlert::query()->whereIn('id', $alertIds)->with(['variant.product', 'location'])->orderBy('id')->get()
            ->map(static fn (InventoryAlert $a): array => [
                'alert_id' => $a->id,
                'kind' => $a->kind->value,
                'variant_id' => $a->variant_id,
                'sku' => (string) $a->variant->sku,
                'product_name' => (string) $a->variant->product->name,
                'location_code' => $a->location->code,
                'expected' => (int) $a->expected,
                'actual' => (int) $a->actual,
            ])->values()->all();
    }
}
