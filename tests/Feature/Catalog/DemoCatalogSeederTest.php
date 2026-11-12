<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\RelationType;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductRelation;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\ImagePipeline;
use App\Domain\Catalog\Services\MediaUrls;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * P3-DAT-04 — the demo catalog: `migrate:fresh --seed` produces the approved design's
 * catalogue (docs/_design/03-storefront-spec.md PART C), with its Figma photos when enabled.
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

        expect($db->table('products')->count())->toBe(16)
            ->and($db->table('product_variants')->count())->toBe(28)
            ->and($db->table('product_variants')->where('is_default', true)->count())->toBe(16)
            ->and($db->table('product_images')->count())->toBe(0); // DEMO_SEED_IMAGES unset → off outside local
    } finally {
        DB::purge('demo_seed');
        @unlink($file);
    }
})->group('slow')->skip(fn (): bool => app()->configurationIsCached(), 'A cached config would ignore the throwaway DB env and hit the dev database.');

it('seeds the PART C catalogue through DatabaseSeeder', function (): void {
    Queue::fake();

    $this->seed(DatabaseSeeder::class);

    $products = Product::query()->published()->with(['variants', 'images', 'categories', 'brand'])->get();

    expect($products)->toHaveCount(16)
        ->and($products->every(fn (Product $p): bool => $p->name_bn !== null && $p->brand !== null && $p->categories->contains('id', $p->primary_category_id)))->toBeTrue()
        ->and($products->every(fn (Product $p): bool => $p->variants->where('is_default', true)->count() === 1))->toBeTrue()
        ->and($products->every(fn (Product $p): bool => $p->age_min_months !== null && $p->age_min_months <= $p->age_max_months))->toBeTrue()
        ->and($products->every(fn (Product $p): bool => $p->rating_avg !== null && $p->rating_count > 0))->toBeTrue()
        ->and($products->where('type', ProductType::Variable)->pluck('slug')->sort()->values()->all())->toBe([
            'crayola-washable-super-tips-20-markers', 'hot-wheels-10-car-gift-pack', 'intex-dinosaur-inflatable-kids-pool',
            'lego-classic-creative-bricks-484', 'little-tikes-first-slide',
        ])
        ->and(ProductImage::query()->count())->toBe(0) // images are off outside `local` unless DEMO_SEED_IMAGES says so
        ->and(ProductRelation::query()->count())->toBe(4)
        ->and(Queue::pushedJobs())->toBe([]);

    // Every default variant carries the spec SKU, price and compare-at (C1).
    foreach (DemoCatalogSeeder::PRODUCTS as $slug => $spec) {
        $default = $products->firstWhere('slug', $slug)?->variants->firstWhere('is_default', true);

        expect($default?->sku)->toBe($spec['sku'])
            ->and($default?->price->toDecimal())->toBe($spec['price'])
            ->and($default?->compare_at_price?->toDecimal())->toBe($spec['compare_at'] ?? null);
    }

    $lego = $products->firstWhere('slug', 'lego-classic-creative-bricks-484');
    expect($lego?->name)->toBe('LEGO Classic Creative Bricks 484 pcs')
        ->and((float) $lego?->rating_avg)->toBe(4.7) // DECIMAL(3,2): SQLite reads it back as a float
        ->and($lego?->rating_count)->toBe(42)
        ->and($lego?->variants)->toHaveCount(6)
        ->and($lego?->variants->firstWhere('is_default', true)?->name)->toBe('484 pcs / Box')
        ->and($lego?->variants->firstWhere('name', '90 pcs / Tub')?->price->toDecimal())->toBe('1450.00')
        ->and($lego?->variants->firstWhere('name', '90 pcs / Tub')?->sku)->toStartWith('TM-');

    // C1.1: the bestsellers are the featured eight, in rank order under the `featured` sort.
    $featured = Product::query()->where('is_featured', true)->orderByDesc('published_at')->orderByDesc('id')->pluck('slug')->all();
    expect($featured)->toBe([
        'hot-wheels-10-car-gift-pack', 'lego-classic-creative-bricks-484', 'play-doh-rainbow-starter-10-pack',
        'fisher-price-smart-stages-puppy', 'crayola-washable-super-tips-20-markers', 'mideer-wooden-shape-sorting-puzzle',
        'hape-pound-tap-bench-xylophone', 'rubiks-cube-3x3-original',
    ]);

    $upsell = ProductRelation::query()->where('type', RelationType::Upsell->value)->sole();
    expect($upsell->relatedProduct->defaultVariant?->price->greaterThan($upsell->product->defaultVariant->price))->toBeTrue();
});

it('is idempotent', function (): void {
    $this->seed(DemoCatalogSeeder::class);
    $this->seed(DemoCatalogSeeder::class);

    expect(Product::query()->count())->toBe(16)
        ->and(ProductVariant::query()->count())->toBe(11 + 6 + 3 + 3 + 2 + 3)
        ->and(ProductImage::query()->count())->toBe(0)
        ->and(ProductRelation::query()->count())->toBe(4);
});

it('attaches images only when enabled: on in local by default, off elsewhere, DEMO_SEED_IMAGES overrides', function (): void {
    expect(DemoCatalogSeeder::imagesEnabled())->toBeFalse(); // testing

    config(['media.demo_seed_images' => true]);
    expect(DemoCatalogSeeder::imagesEnabled())->toBeTrue();

    config(['media.demo_seed_images' => 'false']);
    app()->detectEnvironment(fn (): string => 'local');
    expect(DemoCatalogSeeder::imagesEnabled())->toBeFalse();

    config(['media.demo_seed_images' => null]);
    expect(DemoCatalogSeeder::imagesEnabled())->toBeTrue();
    app()->detectEnvironment(fn (): string => 'testing');
});

it('attaches a product\'s Figma photo through the upload pipeline, with ready renditions and alt text', function (): void {
    Storage::fake('public');
    Storage::fake('local');
    config(['queue.default' => 'database']); // the seeder must not depend on a sync queue to finish the job
    $this->seed(DemoCatalogSeeder::class);
    Event::fake([ProductChanged::class]);

    $product = Product::query()->where('slug', 'rubiks-cube-3x3-original')->sole();
    $image = (new DemoCatalogSeeder)->attachImage($product, 'TMB-RUB-3X3', $product->name, (string) $product->name_bn);

    expect($image->processing_status)->toBe(ImageProcessingStatus::Ready)
        ->and($image->rendition_widths)->toBe([160, 320, 480, 800]) // the 1024 px export is never upscaled to 1200
        ->and($image->is_primary)->toBeTrue()
        ->and($image->alt_text)->toBe("Rubik's Cube 3×3 Original")
        ->and($image->alt_text_bn)->toBe('রুবিক্স কিউব 3×3 অরিজিনাল')
        ->and($image->hasCompleteAlt())->toBeTrue()
        ->and([$image->width, $image->height])->toBe([1024, 1024])
        ->and(config('queue.default'))->toBe('database');

    // A re-encoded WebP master on the private disk — never the PNG bytes — and every rendition public.
    $master = Storage::disk('local')->get("{$image->path}/master.webp");
    expect(ImagePipeline::magicType((string) $master))->toBe('image/webp');
    foreach ([160, 320, 480, 800] as $width) {
        foreach (['webp', 'avif'] as $format) {
            Storage::disk('public')->assertExists(MediaUrls::renditionPath((string) $image->path, $width, $format));
        }
    }

    expect(DB::table('jobs')->count())->toBe(0);
    Event::assertDispatched(ProductChanged::class, fn (ProductChanged $e): bool => $e->productId === $product->id);
})->group('slow');

it('seeds opening stock through the ledger, with sold-out and low-stock demo states', function (): void {
    $this->seed(DemoCatalogSeeder::class);
    $this->seed(DemoCatalogSeeder::class); // idempotent: one opening balance per variant

    $stock = fn (string $slug) => App\Domain\Inventory\Models\StockLevel::query()
        ->join('product_variants', 'product_variants.id', '=', 'stock_levels.variant_id')
        ->join('products', 'products.id', '=', 'product_variants.product_id')
        ->where('products.slug', $slug)->orderBy('product_variants.position')->pluck('stock_levels.on_hand')->all();

    expect(App\Domain\Inventory\Models\StockMovement::query()->count())->toBe(ProductVariant::query()->count() - 3) // zero-quantity variants record nothing
        ->and(App\Domain\Inventory\Models\StockMovement::query()->where('type', '!=', 'opening_balance')->count())->toBe(0)
        ->and($stock('clementoni-galileo-my-first-microscope'))->toBe([])
        ->and($stock('barbie-dreamhouse-pool-party-doll'))->toBe([3])
        ->and($stock('rubiks-cube-3x3-original'))->toBe([24])
        ->and(app(App\Domain\Inventory\Services\StockAvailability::class)->inStockProductIds(Product::query()->pluck('id')->all()))
        ->toHaveCount(15);

    $lego = Product::query()->where('slug', 'lego-classic-creative-bricks-484')->sole();
    $byName = $lego->variants->mapWithKeys(fn (ProductVariant $v) => [(string) $v->name => app(App\Domain\Inventory\Services\StockAvailability::class)->forVariants([$v->id])[$v->id]]);
    expect($byName->filter(fn (int $q) => $q === 0)->count())->toBe(1)
        ->and($byName->contains(9))->toBeTrue();
});
