<?php

declare(strict_types=1);

use App\Domain\Cart\DTOs\PricingInput;
use App\Domain\Cart\Services\PricingPipeline;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Reviews\Enums\ReviewStatus;
use App\Domain\Catalog\Reviews\Models\Review;
use App\Domain\Content\Blog\Enums\PostStatus;
use App\Domain\Content\Blog\Enums\PostType;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Enums\BannerPlacement;
use App\Domain\Content\Enums\MenuLocation;
use App\Domain\Content\Models\Banner;
use App\Domain\Content\Models\Menu;
use App\Domain\Content\Services\MenuTree;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Marketing\Enums\PromotionType;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Services\FlashSaleLookup;
use App\Domain\Seo\Services\RedirectMap;
use App\Domain\Seo\Services\RobotsTxt;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BrandDemoSeeder;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\CategoryDemoSeeder;
use Database\Seeders\CmsHomeBlocksSeeder;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoContentSeeder;
use Database\Seeders\DemoInventorySeeder;
use Database\Seeders\DemoMarketingSeeder;
use Database\Seeders\DemoReviewsSeeder;
use Database\Seeders\DemoSeoSeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\MediaFoldersSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StaticPagesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Demo storefront content: marketing (before orders), reviews (after), blog/banners/menus/
 * testimonials and SEO. Seeded on the base reference + demo catalogue only — no orders —
 * so every review is unverified here; the verified path is the DemoDataSeeder run.
 */

const DEMO_CONTENT_TABLES = ['coupons', 'promotions', 'flash_sale_items', 'reviews', 'customers', 'blog_posts', 'blog_categories', 'blog_tags',
    'blog_authors', 'blog_post_products', 'banners', 'menus', 'menu_items', 'testimonials', 'seo_meta', 'slug_history', 'redirects', 'notification_templates'];

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['demo.transactions' => true, 'services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);

    $this->seed([RolesAndPermissionsSeeder::class, GeographySeeder::class, CatalogReferenceSeeder::class, MediaFoldersSeeder::class,
        DeliverySeeder::class, CmsHomeBlocksSeeder::class, StaticPagesSeeder::class]);
    User::factory()->create(['email' => 'owner@toymart.test'])->assignRole(Role::SuperAdmin->value);
    $this->seed([CategoryDemoSeeder::class, BrandDemoSeeder::class, DemoCatalogSeeder::class, DemoInventorySeeder::class]);
    $this->seed([DemoMarketingSeeder::class, DemoReviewsSeeder::class, DemoContentSeeder::class, DemoSeoSeeder::class]);
});

afterEach(function (): void {
    expect(CarbonImmutable::hasTestNow())->toBeFalse('a seeder left the clock frozen');
});

it('seeds WELCOME100 and TOY10, and both apply to a cart', function (): void {
    $lego = ProductVariant::query()->where('product_id', Product::query()->where('slug', 'lego-classic-creative-bricks-484')->value('id'))->where('is_default', true)->sole();
    $price = fn (string $code) => app(PricingPipeline::class)->price(new PricingInput(lines: [$lego->id => 1], couponCode: $code));

    expect(DemoMarketingSeeder::ORDER_COUPONS)->toBe(['WELCOME100', 'TOY10'])
        ->and($price('WELCOME100')->coupon?->applied())->toBeTrue()
        ->and($price('WELCOME100')->couponDiscount->toDecimal())->toBe('100.00')
        ->and($price('TOY10')->couponDiscount->toDecimal())->toBe('300.00'); // 10% of 4250, capped at 300
});

it('seeds every coupon and promotion state', function (): void {
    $now = now();
    $coupons = Coupon::query()->whereNull('batch')->get()->keyBy('code');

    expect($coupons->keys()->sort()->values()->all())->toBe(collect(array_keys(DemoMarketingSeeder::COUPONS))->sort()->values()->all())
        ->and($coupons['PUJA200']->ends_at?->lessThan($now))->toBeTrue()
        ->and($coupons['FLASH50']->is_active)->toBeFalse()
        ->and($coupons['WINTER250']->starts_at?->greaterThan($now))->toBeTrue()
        ->and(Coupon::query()->where('batch', DemoMarketingSeeder::BATCH)->where('usage_limit_total', 1)->count())->toBe(20);

    $promotions = Promotion::query()->get();
    expect($promotions->map(fn (Promotion $p): string => $p->statusAt($now))->unique()->sort()->values()->all())
        ->toBe(['ended', 'inactive', 'running', 'scheduled'])
        ->and($promotions->pluck('type')->map(fn (PromotionType $t): string => $t->value)->unique()->sort()->values()->all())
        ->toBe(['bundle', 'buy_x_get_y', 'discount', 'flash_sale', 'free_delivery', 'tiered'])
        ->and($promotions->where('is_stackable', false)->count())->toBeGreaterThan(0);
});

it('runs one live flash sale within stock, and has a finished one', function (): void {
    $running = app(FlashSaleLookup::class)->running();

    expect($running)->toHaveCount(1)
        ->and($running->first()?->flashItems)->toHaveCount(4)
        ->and(Promotion::query()->where('type', 'flash_sale')->get()->filter(fn (Promotion $p): bool => $p->statusAt(now()) === 'ended'))->toHaveCount(1);

    foreach ($running->first()->flashItems->load('variant') as $item) { // eager: the suite forbids lazy loads (Phase 9)
        $available = (int) StockLevel::query()->where('variant_id', $item->variant_id)->sum(DB::raw('on_hand - reserved'));
        expect($item->allotment)->toBeGreaterThan(0)->toBeLessThanOrEqual($available)
            ->and($item->sale_price->lessThan($item->variant->price))->toBeTrue();
    }
});

it('seeds reviews in every status and keeps each product rating backed by approved reviews', function (): void {
    expect(Review::query()->count())->toBe(count(DemoReviewsSeeder::REVIEWS))
        ->and(Review::query()->where('status', ReviewStatus::Approved)->count())->toBe(29)
        ->and(Review::query()->where('status', ReviewStatus::Pending)->count())->toBe(7)
        ->and(Review::query()->where('status', ReviewStatus::Rejected)->count())->toBe(4)
        ->and(Review::query()->whereNotNull('admin_reply')->count())->toBe(5)
        ->and(Review::query()->where('locale', 'bn')->count())->toBeGreaterThan(10)
        ->and(Review::query()->where('is_verified_purchase', true)->count())->toBe(0); // no orders in this test

    foreach (Product::query()->get() as $product) {
        $approved = Review::query()->where('product_id', $product->id)->where('status', ReviewStatus::Approved)->count();
        expect($approved)->toBeGreaterThan(0)->and($product->rating_count)->toBe($approved);
    }
});

it('publishes blog posts in both locales, with a draft, a scheduled post and a buying guide', function (): void {
    $live = BlogPost::query()->live()->get();

    expect($live)->toHaveCount(6)
        ->and($live->whereNotNull('title_bn')->whereNotNull('body_bn')->count())->toBe(6)
        ->and(BlogPost::query()->where('status', PostStatus::Draft)->count())->toBe(1)
        ->and(BlogPost::query()->where('status', PostStatus::Scheduled)->where('published_at', '>', now())->count())->toBe(1);

    $guide = BlogPost::query()->where('slug', DemoContentSeeder::GUIDE_SLUG)->sole();
    expect($guide->type)->toBe(PostType::BuyingGuide)
        ->and(DB::table('blog_post_products')->where('post_id', $guide->id)->whereNotNull('section_id')->count())->toBe(9);
});

it('seeds banners, testimonials and three resolvable menus', function (): void {
    expect(Banner::query()->count())->toBe(5)
        ->and(Banner::query()->where('placement', BannerPlacement::InfoStrip)->count())->toBe(1)
        ->and(Banner::query()->where('starts_at', '>', now())->count())->toBe(1)
        ->and(DB::table('testimonials')->where('is_published', true)->count())->toBe(5);

    $tree = app(MenuTree::class);
    foreach (MenuLocation::cases() as $location) {
        $menu = Menu::query()->where('location', $location->value)->sole();
        expect($menu->is_published)->toBeTrue()->and($tree->storefront($menu))->not->toBeEmpty();
    }

    $footer = $tree->storefront(Menu::query()->where('location', 'footer')->sole());
    expect(collect($footer[0]['children'])->pluck('href')->all())->toBe([
        '/categories/building-blocks', '/categories/baby-toddler', '/categories/puzzles-games', '/categories/art-craft', '/categories/science-learning',
    ]);
});

it('records slug history and redirects that resolve without chains', function (): void {
    $map = collect(app(RedirectMap::class)->build())->keyBy('from');

    expect($map['/products/lego-classic-484-pcs']['to'])->toBe('/products/lego-classic-creative-bricks-484')
        ->and($map['/categories/puzzles-and-board-games']['to'])->toBe('/categories/puzzles-games')
        ->and($map['/product/lego-484-classic-set']['to'])->toBe('/products/lego-classic-creative-bricks-484')
        ->and($map['/eid-offer']['status'])->toBe(302)
        ->and($map->has('/winter-sale'))->toBeFalse() // inactive
        ->and(RobotsTxt::stored())->toContain('Disallow: /track')
        ->and(DB::table('seo_meta')->count())->toBeGreaterThanOrEqual(count(DemoSeoSeeder::META))
        ->and(DB::table('notification_templates')->count())->toBeGreaterThan(0);
});

it('is idempotent and leaves both invariants intact', function (): void {
    $before = collect(DEMO_CONTENT_TABLES)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()]);

    $this->seed([DemoMarketingSeeder::class, DemoReviewsSeeder::class, DemoContentSeeder::class, DemoSeoSeeder::class]);

    $before->each(fn (int $count, string $t) => expect(DB::table($t)->count())->toBe($count, "{$t} grew on re-run"));
    expect(Artisan::call('toymart:inventory:reconcile'))->toBe(0, Artisan::output())
        ->and(Artisan::call('toymart:finance:verify'))->toBe(0, Artisan::output());
});
