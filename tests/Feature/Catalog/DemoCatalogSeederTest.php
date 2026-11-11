<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\RelationType;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductRelation;
use App\Domain\Catalog\Models\ProductVariant;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/*
 * P3-DAT-04 — the demo catalog: `migrate:fresh --seed` produces a browsable catalog.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('succeeds as a real `migrate:fresh --seed` on a throwaway SQLite file', function (): void {
    // A separate process: migrate:fresh cannot run inside RefreshDatabase's transaction.
    $file = tempnam(sys_get_temp_dir(), 'toymart-seed');

    try {
        $result = Process::path(base_path())
            ->env(['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file, 'DB_URL' => '', 'QUEUE_CONNECTION' => 'sync', 'STOREFRONT_URL' => ''])
            ->timeout(120)
            ->run([PHP_BINARY, 'artisan', 'migrate:fresh', '--seed', '--force', '--no-interaction']);

        expect($result->exitCode())->toBe(0, $result->errorOutput().$result->output());

        config(['database.connections.demo_seed' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]]);
        $db = DB::connection('demo_seed');

        expect($db->table('products')->count())->toBe(13)
            ->and($db->table('product_variants')->count())->toBe(24)
            ->and($db->table('product_variants')->where('is_default', true)->count())->toBe(13);
    } finally {
        DB::purge('demo_seed');
        @unlink($file);
    }
})->group('slow')->skip(fn (): bool => app()->configurationIsCached(), 'A cached config would ignore the throwaway DB env and hit the dev database.');

it('seeds a browsable catalog through DatabaseSeeder', function (): void {
    Queue::fake();

    $this->seed(DatabaseSeeder::class);

    $products = Product::query()->published()->with(['variants', 'images', 'categories'])->get();

    expect($products)->toHaveCount(13)
        ->and($products->every(fn (Product $p): bool => $p->name_bn !== null && $p->categories->contains('id', $p->primary_category_id)))->toBeTrue()
        ->and($products->every(fn (Product $p): bool => $p->variants->where('is_default', true)->count() === 1))->toBeTrue()
        ->and($products->every(fn (Product $p): bool => $p->age_min_months !== null && $p->age_min_months <= $p->age_max_months))->toBeTrue()
        ->and($products->where('type', ProductType::Variable))->toHaveCount(3)
        ->and(ProductImage::query()->whereNull('path')->count())->toBe(26)
        ->and(ProductRelation::query()->count())->toBe(4)
        ->and(Queue::pushedJobs())->toBe([]);

    $tricycle = $products->firstWhere('slug', 'kids-tricycle');
    expect($tricycle?->variants)->toHaveCount(9)
        ->and($tricycle?->variants->firstWhere('name', 'Red / Large')?->price->toDecimal())->toBe('4900.00')
        ->and($tricycle?->variants->firstWhere('name', 'Blue / Small')?->price->toDecimal())->toBe('3800.00');

    $upsell = ProductRelation::query()->where('type', RelationType::Upsell->value)->sole();
    expect($upsell->relatedProduct->defaultVariant?->price->greaterThan($upsell->product->defaultVariant->price))->toBeTrue();
});

it('is idempotent', function (): void {
    $this->seed(DemoCatalogSeeder::class);
    $this->seed(DemoCatalogSeeder::class);

    expect(Product::query()->count())->toBe(13)
        ->and(ProductVariant::query()->count())->toBe(10 + 9 + 2 + 3)
        ->and(ProductImage::query()->count())->toBe(26)
        ->and(ProductRelation::query()->count())->toBe(4);
});
