<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Content\Blocks\Actions\PublishLayout;
use App\Domain\Content\Blocks\Enums\BlockPlacement;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Blocks\Services\ScheduleBoundarySweep;
use App\Domain\Content\Blog\Enums\PostStatus;
use App\Domain\Content\Blog\Enums\PostType;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Content\Models\Testimonial;
use App\Support\PreviewToken;
use Carbon\CarbonImmutable;
use Database\Seeders\CmsHomeBlocksSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Content\CmsBlocks\CmsKit;

/*
 * Storefront CMS homepage (Phase 7): GET /storefront/content/home renders the PUBLISHED blocks
 * for one locale at the current instant; /preview renders the draft behind a preview token.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

function cmsPublish(): CmsLayoutVersion
{
    return app(PublishLayout::class)->handle(BlockPlacement::Home, null);
}

it('answers an empty home before the first publish, so the storefront keeps its built-in page', function (): void {
    CmsKit::home()->assertOk()->assertExactJson(['version' => null, 'published_at' => null, 'preview' => false, 'blocks' => []]);
});

it('requires the storefront token and an allowlisted locale', function (): void {
    $this->getJson(CmsKit::STOREFRONT.'?locale=en')->assertUnauthorized();
    CmsKit::home('fr')->assertStatus(400);
    CmsKit::home('en', ['debug' => '1'])->assertStatus(400);
});

it('renders every block type in English and Bangla, Bangla falling back to English', function (): void {
    $image = Media::factory()->create(['alt_text' => 'Bricks', 'alt_text_bn' => 'ব্রিক']);
    $category = Category::factory()->create(['name' => 'Puzzles', 'name_bn' => 'পাজল', 'slug' => 'puzzles']);
    $brand = Brand::factory()->create(['name' => 'LEGO', 'name_bn' => 'লেগো', 'slug' => 'lego', 'logo_path' => 'brands/lego.webp']);
    CmsKit::product('stacker', 4, ['brand_id' => $brand->id, 'primary_category_id' => $category->id]);
    Testimonial::query()->create(['name' => 'Rina', 'name_bn' => 'রিনা', 'quote' => 'Fast delivery.', 'quote_bn' => 'দ্রুত ডেলিভারি।', 'rating' => 5, 'is_published' => true, 'position' => 1]);
    Testimonial::query()->create(['name' => 'Hidden', 'quote' => 'Draft.', 'is_published' => false, 'position' => 0]);
    BlogPost::query()->create([
        'type' => PostType::Article, 'slug' => 'first-toys', 'title' => 'First toys', 'title_bn' => 'প্রথম খেলনা', 'excerpt' => 'A guide.',
        'status' => PostStatus::Published, 'published_at' => now()->subDay(), 'reading_minutes' => 4, 'cover_media_id' => $image->id,
    ]);

    CmsKit::block('hero', ['slides' => [['headline' => 'Eid toys', 'headline_bn' => 'ঈদের খেলনা', 'cta_label' => 'Shop', 'cta_href' => '/toys', 'media_id' => $image->id]]]);
    CmsKit::block('category_tiles', ['title' => 'Categories', 'source' => 'manual', 'category_ids' => [$category->id]]);
    CmsKit::block('age_tiles');
    CmsKit::block('product_rail', ['title' => 'New', 'title_bn' => 'নতুন', 'rule' => 'new', 'limit' => 4]);
    CmsKit::block('brand_strip', ['title' => 'Brands', 'brand_ids' => [$brand->id]]);
    CmsKit::block('usp_band');
    CmsKit::block('promo_split', ['headline' => 'Learn', 'headline_bn' => '', 'media_id' => $image->id, 'image_side' => 'right']);
    CmsKit::block('testimonials', ['title' => 'Parents say']);
    CmsKit::block('blog_teaser', ['title' => 'Blog']);
    CmsKit::block('rich_text', ['html' => '<p>About us</p>', 'html_bn' => '<p>আমাদের কথা</p>']);
    CmsKit::block('faq', ['title' => 'FAQ', 'items' => [['question' => 'COD?', 'question_bn' => 'ক্যাশ অন ডেলিভারি?', 'answer' => 'Yes.', 'answer_bn' => 'হ্যাঁ।']]]);
    cmsPublish();

    $en = CmsKit::home('en')->assertOk();
    $bn = CmsKit::home('bn')->assertOk();

    $all = ['hero', 'category_tiles', 'age_tiles', 'product_rail', 'brand_strip', 'usp_band', 'promo_split', 'testimonials', 'blog_teaser', 'rich_text', 'faq'];
    expect(CmsKit::types($en))->toBe($all)->and(CmsKit::types($bn))->toBe($all)
        ->and($en->json('version'))->toBe(1)
        ->and(array_keys($en->json('blocks.0')))->toBe(['id', 'type', 'show_mobile', 'show_desktop', 'content']);

    // Hero: localised copy, image with renditions and the media alt in the locale.
    expect($en->json('blocks.0.content.slides.0.headline'))->toBe('Eid toys')
        ->and($bn->json('blocks.0.content.slides.0.headline'))->toBe('ঈদের খেলনা')
        ->and($en->json('blocks.0.content.slides.0.image.alt'))->toBe('Bricks')
        ->and($bn->json('blocks.0.content.slides.0.image.alt'))->toBe('ব্রিক')
        ->and($en->json('blocks.0.content.slides.0.image.srcset_avif'))->toContain('w')
        ->and($en->json('blocks.0.content.slides.0'))->not->toHaveKey('headline_bn');

    expect($en->json('blocks.1.categories.0'))->toMatchArray(['slug' => 'puzzles', 'name' => 'Puzzles', 'name_bn' => 'পাজল'])
        ->and($en->json('blocks.2.age_counts'))->toBeArray()
        ->and($en->json('blocks.3.products.0.slug'))->toBe('stacker')
        ->and($en->json('blocks.3.see_all_href'))->toBe('/toys?sort=newest')
        ->and($bn->json('blocks.3.content.title'))->toBe('নতুন')
        ->and($bn->json('blocks.4.brands.0'))->toMatchArray(['name' => 'লেগো', 'slug' => 'lego'])
        ->and($en->json('blocks.5.content.items'))->toHaveCount(4)
        ->and($bn->json('blocks.6.content.headline'))->toBe('Learn') // empty Bangla → English
        ->and($en->json('blocks.6.content.image.url'))->toBeString()
        ->and($en->json('blocks.6.content.image_side'))->toBe('right')
        ->and($en->json('blocks.7.testimonials'))->toHaveCount(1)
        ->and($bn->json('blocks.7.testimonials.0'))->toMatchArray(['name' => 'রিনা', 'quote' => 'দ্রুত ডেলিভারি।', 'rating' => 5])
        ->and($bn->json('blocks.8.posts.0'))->toMatchArray(['slug' => 'first-toys', 'type' => 'article', 'title' => 'First toys', 'title_bn' => 'প্রথম খেলনা', 'reading_minutes' => 4])
        ->and($en->json('blocks.9.content.html'))->toBe('<p>About us</p>')
        ->and($bn->json('blocks.9.content.html'))->toBe('<p>আমাদের কথা</p>')
        ->and($bn->json('blocks.10.content.items.0'))->toBe(['question' => 'ক্যাশ অন ডেলিভারি?', 'answer' => 'হ্যাঁ।']);

    CmsKit::assertStockInvariant();
});

it('applies per-locale and per-device visibility and the enabled flag', function (): void {
    CmsKit::block('faq', [], ['show_bn' => false]);
    CmsKit::block('usp_band', [], ['show_mobile' => false]);
    CmsKit::block('rich_text', [], ['is_enabled' => false]);
    cmsPublish();

    expect(CmsKit::types(CmsKit::home('en')))->toBe(['faq', 'usp_band'])
        ->and(CmsKit::types(CmsKit::home('bn')))->toBe(['usp_band'])
        ->and(CmsKit::home('en')->json('blocks.1'))->toMatchArray(['show_mobile' => false, 'show_desktop' => true]);
});

it('shows a scheduled block only inside its window, at Dhaka boundaries', function (): void {
    // Admin entered 15 Jun 00:00 → 22 Jun 23:59 Dhaka = 14 Jun 18:00Z → 22 Jun 17:59Z.
    CmsKit::block('faq', [], ['starts_at' => '2026-06-14 18:00:00', 'ends_at' => '2026-06-22 17:59:00']);
    CmsKit::block('usp_band');
    cmsPublish();

    $at = static function (string $instant): array {
        CarbonImmutable::setTestNow($instant);
        Illuminate\Support\Carbon::setTestNow($instant);

        return CmsKit::types(CmsKit::home());
    };

    expect($at('2026-06-14T17:59:59Z'))->toBe(['usp_band'])      // 14 Jun 23:59:59 Dhaka
        ->and($at('2026-06-14T18:00:00Z'))->toBe(['faq', 'usp_band']) // 15 Jun 00:00 Dhaka
        ->and($at('2026-06-22T17:58:59Z'))->toBe(['faq', 'usp_band'])
        ->and($at('2026-06-22T17:59:00Z'))->toBe(['usp_band']);     // end is exclusive

    CarbonImmutable::setTestNow();
    Illuminate\Support\Carbon::setTestNow();
});

it('hides a rail whose products are all sold out, unlisted or archived, and drops sold-out cards', function (): void {
    CmsKit::product('sold-out', 0);
    CmsKit::product('draft', 3, ['status' => ProductStatus::Draft]);
    CmsKit::product('unpublished', 3, ['published_at' => now()->addWeek()]);
    $archived = CmsKit::product('archived', 3);
    $archived->delete();

    CmsKit::block('product_rail', ['title' => 'New', 'rule' => 'new']);
    CmsKit::block('faq');
    cmsPublish();

    expect(CmsKit::types(CmsKit::home()))->toBe(['faq']);

    CmsKit::product('in-stock', 2);
    expect(CmsKit::home()->json('blocks.0.products.*.slug'))->toBe(['in-stock']);

    CmsKit::assertStockInvariant();
});

it('keeps a manual pick in order, skipping sold-out picks, and honours min_items', function (): void {
    $a = CmsKit::product('a', 2);
    $b = CmsKit::product('b', 0);
    $c = CmsKit::product('c', 2);

    CmsKit::block('product_rail', ['title' => 'Picks', 'rule' => 'manual', 'product_ids' => [$c->id, $b->id, $a->id], 'min_items' => 2, 'limit' => 4]);
    cmsPublish();

    $home = CmsKit::home();
    expect($home->json('blocks.0.products.*.slug'))->toBe(['c', 'a'])
        ->and($home->json('blocks.0.see_all_href'))->toBeNull();

    // Below min_items the rail hides rather than degrading (rule 25c).
    $c->delete();
    expect(CmsKit::types(CmsKit::home()))->toBe([]);
});

it('ranks best sellers by units sold in the window', function (): void {
    $quiet = CmsKit::product('quiet', 5, ['is_featured' => true]);
    $popular = CmsKit::product('popular', 5);
    $order = App\Domain\Ordering\Models\Order::factory()->status(App\Domain\Ordering\Enums\OrderStatus::Delivered)->create(['created_at' => now()->subDays(2)]);
    App\Domain\Ordering\Models\OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => $popular->variants()->value('id'), 'quantity' => 3, 'line_total' => '3750.00']);
    $old = App\Domain\Ordering\Models\Order::factory()->status(App\Domain\Ordering\Enums\OrderStatus::Delivered)->create(['created_at' => now()->subDays(60)]);
    App\Domain\Ordering\Models\OrderItem::factory()->create(['order_id' => $old->id, 'variant_id' => $quiet->variants()->value('id'), 'quantity' => 9, 'line_total' => '11250.00']);

    CmsKit::block('product_rail', ['title' => 'Best', 'rule' => 'best_sellers', 'window_days' => 30]);
    cmsPublish();

    expect(CmsKit::home()->json('blocks.0.products.*.slug'))->toBe(['popular', 'quiet']);
});

it('serves the draft only behind a valid, unexpired preview token', function (): void {
    CmsKit::block('faq');
    cmsPublish();
    CmsKit::block('usp_band', [], ['starts_at' => now()->addDays(3)]);

    $token = PreviewToken::issue('home_draft', 1, 600);

    CmsKit::preview(null)->assertForbidden();
    CmsKit::preview('home_draft.1.9999999999.'.str_repeat('a', 64))->assertForbidden(); // forged signature
    CmsKit::preview(PreviewToken::issue('blog_post', 1, 600))->assertForbidden();        // another type
    CmsKit::preview($token, 'en', ['evil' => '1'])->assertStatus(400);

    $draft = CmsKit::preview($token)->assertOk()->assertJsonPath('preview', true)->assertJsonPath('version', null);
    expect(CmsKit::types($draft))->toBe(['faq'])
        ->and(CmsKit::types(CmsKit::preview($token, 'en', ['at' => now()->addDays(4)->toIso8601String()])))->toBe(['faq', 'usp_band'])
        ->and(CmsKit::types(CmsKit::home()))->toBe(['faq']); // live untouched

    // Expiry.
    CarbonImmutable::setTestNow(now()->addMinutes(11));
    CmsKit::preview($token)->assertForbidden();
    CarbonImmutable::setTestNow();
});

it('serves the new version right after a publish (Laravel cache forgotten)', function (): void {
    CmsKit::block('faq');
    cmsPublish();
    expect(CmsKit::types(CmsKit::home()))->toBe(['faq']);

    CmsKit::block('usp_band');
    expect(CmsKit::types(CmsKit::home()))->toBe(['faq']); // draft only
    cmsPublish();
    expect(CmsKit::types(CmsKit::home()))->toBe(['faq', 'usp_band']);
});

it('revalidates content:home when a schedule boundary of the live version passes', function (): void {
    Bus::fake([RevalidateStorefrontTags::class]);
    CarbonImmutable::setTestNow('2026-06-14T17:58:00Z');

    CmsKit::block('faq', [], ['starts_at' => '2026-06-14 18:00:00', 'ends_at' => '2026-06-14 20:00:00']);
    cmsPublish();
    Bus::fake([RevalidateStorefrontTags::class]); // ignore the publish's own revalidation

    $sweep = app(ScheduleBoundarySweep::class);
    expect($sweep->run(CarbonImmutable::parse('2026-06-14T17:59:00Z')))->toBe([])
        ->and($sweep->run(CarbonImmutable::parse('2026-06-14T18:00:00Z')))->toBe(['content:home'])
        ->and($sweep->run(CarbonImmutable::parse('2026-06-14T18:01:00Z')))->toBe([])
        ->and($sweep->run(CarbonImmutable::parse('2026-06-14T20:00:30Z')))->toBe(['content:home']);
    Bus::assertDispatchedTimes(RevalidateStorefrontTags::class, 2);

    // The command runs the same sweep.
    Cache::flush();
    $this->artisan('toymart:content:revalidate-schedule')->assertSuccessful();
    CarbonImmutable::setTestNow();
});

it('seeds the default homepage once, published as version 1, without queueing a revalidation', function (): void {
    Bus::fake();
    $this->seed(CmsHomeBlocksSeeder::class);
    $this->seed(CmsHomeBlocksSeeder::class);

    expect(CmsLayoutVersion::query()->count())->toBe(1)
        ->and(array_column(CmsLayoutVersion::latestFor(BlockPlacement::Home)?->blocks ?? [], 'type'))
        ->toBe(['hero', 'category_tiles', 'flash_sale', 'age_tiles', 'product_rail', 'product_rail', 'usp_band', 'testimonials', 'blog_teaser']);
    Bus::assertNothingDispatched();

    // With an empty catalogue the data-driven blocks hide themselves; nothing renders empty.
    expect(CmsKit::types(CmsKit::home()))->toBe(['hero', 'flash_sale', 'age_tiles', 'usp_band']);
});
