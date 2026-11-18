<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Services\BranchMode;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\LiveDemoCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * The deploy's one-off demo catalog: fills an empty shop, keeps every live setting as it
 * was (with a JSON snapshot on the backups disk), and never runs once a product exists.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    Storage::fake('backups');
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'media.demo_seed_images' => false]);
    $this->seed([RolesAndPermissionsSeeder::class, GeographySeeder::class, DeliverySeeder::class]);
});

it('seeds the catalog with stock on an empty shop and restores the live settings', function (): void {
    Setting::put(BranchMode::KEY, true, SettingGroup::Store);
    Setting::put('store.name', 'Toy Mart Live', SettingGroup::Store);
    Setting::put('payments.secret', 'k-123', SettingGroup::Payment, encrypted: true);
    $before = DB::table('settings')->orderBy('key')->get()->map(fn (object $r): array => (array) $r)->all();

    $this->seed(LiveDemoCatalogSeeder::class);

    expect(Product::query()->count())->toBeGreaterThan(0)
        ->and(Category::query()->count())->toBeGreaterThan(0)
        ->and(DB::table('stock_movements')->sum('quantity'))->toEqual(DB::table('stock_levels')->sum('on_hand'));

    $after = DB::table('settings')->whereIn('key', array_column($before, 'key'))->orderBy('key')->get()
        ->map(fn (object $r): array => (array) $r)->all();
    expect($after)->toEqual($before)
        ->and(Setting::get('payments.secret'))->toBe('k-123');

    $files = Storage::disk('backups')->files();
    expect($files)->toHaveCount(1)
        ->and(json_decode(Storage::disk('backups')->get($files[0]), true))->toHaveCount(count($before));
});

it('does nothing once any product exists', function (): void {
    $this->seed(LiveDemoCatalogSeeder::class);
    $products = Product::query()->count();
    $movements = DB::table('stock_movements')->count();

    $this->seed(LiveDemoCatalogSeeder::class);

    expect(Product::query()->count())->toBe($products)
        ->and(DB::table('stock_movements')->count())->toBe($movements)
        ->and(Storage::disk('backups')->files())->toHaveCount(1);
});
