<?php

declare(strict_types=1);

use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockLedgerReport;
use App\Domain\Inventory\Services\StockOverview;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoInventorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * The demo branches (Figma B · 14 Inventory): three locations, branch stock through the
 * ledger, reorder levels that give the low-stock and overstock tabs real rows.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->seed(DemoCatalogSeeder::class);
});

/** @return array<string, int> "variant:location" → on_hand drift (movements − level), non-zero only */
function demoDrift(): array
{
    $sums = DB::table('stock_movements')->selectRaw('variant_id, location_id, SUM(quantity) as total')->groupBy('variant_id', 'location_id')->get()
        ->mapWithKeys(fn (object $r): array => [$r->variant_id.':'.$r->location_id => (int) $r->total]);
    $levels = DB::table('stock_levels')->get()->mapWithKeys(fn (object $r): array => [$r->variant_id.':'.$r->location_id => (int) $r->on_hand]);

    return $sums->keys()->merge($levels->keys())->unique()
        ->mapWithKeys(fn (string $k): array => [$k => ($sums[$k] ?? 0) - ($levels[$k] ?? 0)])
        ->filter()->all();
}

it('seeds the three Figma locations, the default one renamed in place', function (): void {
    $defaultId = StockLocation::defaultId();
    $this->seed(DemoInventorySeeder::class);

    $locations = StockLocation::query()->orderByDesc('is_default')->orderBy('id')->get();

    expect($locations->pluck('code')->all())->toBe(['dhk', 'ctg', 'syl'])
        ->and($locations[0]->id)->toBe($defaultId)
        ->and($locations[0]->name)->toBe('Dhaka HQ · Mirpur')
        ->and($locations[0]->description)->toBe('Main warehouse · online orders')
        ->and($locations[1]->description)->toBe('Retail branch · POS + pickup')
        ->and($locations[2]->description_bn)->toBe('শুধু পিকআপ · POS নেই')
        ->and($locations->every(fn (StockLocation $l): bool => $l->is_active && $l->name_bn !== null))->toBeTrue();
});

it('puts branch stock in through opening-balance movements, and the ledger still sums to the levels', function (): void {
    $this->seed(DemoInventorySeeder::class);
    $this->seed(DemoInventorySeeder::class); // idempotent

    $ctg = StockLocation::query()->where('code', 'ctg')->sole();
    $syl = StockLocation::query()->where('code', 'syl')->sole();

    expect(StockMovement::query()->where('type', '!=', 'opening_balance')->count())->toBe(0)
        ->and(StockMovement::query()->where('location_id', $ctg->id)->sum('quantity'))->toBeGreaterThan(0)
        ->and(StockMovement::query()->where('location_id', $syl->id)->sum('quantity'))->toBeGreaterThan(0)
        ->and(demoDrift())->toBe([])
        ->and(app(StockLedgerReport::class)->reconciliation([])['balanced'])->toBeTrue();
});

it('leaves the default location stock the storefront reads untouched', function (): void {
    $before = StockLevel::query()->where('location_id', StockLocation::defaultId())->orderBy('variant_id')->pluck('on_hand', 'variant_id')->all();

    $this->seed(DemoInventorySeeder::class);

    expect(StockLevel::query()->where('location_id', StockLocation::defaultId())->where('on_hand', '!=', 0)->orderBy('variant_id')->pluck('on_hand', 'variant_id')->all())
        ->toBe(array_filter($before));
});

it('sets reorder levels that give Dhaka HQ low, out and overstock rows', function (): void {
    $this->seed(DemoInventorySeeder::class);

    $summary = app(StockOverview::class)->summary(StockLocation::defaultId());

    // Low: Barbie (3 ≤ 6), the dump truck (5 ≤ 6), the LEGO box (9 ≤ 12).
    expect($summary['counts']['low'])->toBe(3)
        ->and($summary['counts']['out'])->toBeGreaterThan(0)
        ->and($summary['counts']['overstock'])->toBeGreaterThan(0)
        ->and($summary['locations'])->toHaveCount(3)
        ->and(app(StockOverview::class)->lowCount())->toBe(3);
});
