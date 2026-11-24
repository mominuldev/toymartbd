<?php

declare(strict_types=1);

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\Category;
use App\Domain\Content\Blocks\Actions\PublishLayout;
use App\Domain\Content\Blocks\Enums\BlockType;
use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use App\Domain\Content\Blog\Enums\PostStatus;
use App\Domain\Content\Blog\Enums\PostType;
use App\Domain\Content\Blog\Models\BlogPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Content\CmsBlocks\CmsKit;

/*
 * Smart Junior home sections (Figma 2b6PVOOapPbcaKYIdHWZup page 34:5658): every word, link,
 * image and figure of the age cards, rails, promo banners, "Why Smart Junior", the video reel,
 * the journal and the help card is block content, edited in Pages › Home.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

function sjPublish(): void
{
    app(PublishLayout::class)->handle(LayoutKey::home(), null);
}

/** @return list<string> the fields a 422 (RFC 9457) names */
function sjErrors(Illuminate\Testing\TestResponse $response): array
{
    return collect($response->assertUnprocessable()->json('errors'))->pluck('field')->unique()->values()->all();
}

it('sends the age photo cards with their images, links and tones in the locale (34:5735)', function (): void {
    $photo = Media::factory()->create(['alt_text' => 'Baby with a ball']);
    CmsKit::block('age_tiles', [
        'eyebrow' => 'Age guide', 'eyebrow_bn' => 'বয়স গাইড', 'eyebrow_tone' => 'blue',
        'title' => 'Shop by age', 'sub' => 'Every age plays differently.', 'sub_bn' => 'প্রতিটি বয়সের খেলা আলাদা।',
        'cards' => [
            ['title' => '0–2 years', 'title_bn' => '0–2 বছর', 'line' => 'Sensory & first play', 'href' => '/toys?age=0-12,12-24', 'media_id' => $photo->id],
            ['title' => '2–4 years', 'href' => '/toys?age=24-48', 'tone' => 'green'],
        ],
    ]);
    sjPublish();

    $bn = CmsKit::home('bn')->assertOk();
    expect($bn->json('blocks.0.content'))->toMatchArray(['eyebrow' => 'বয়স গাইড', 'eyebrow_tone' => 'blue', 'sub' => 'প্রতিটি বয়সের খেলা আলাদা।'])
        ->and($bn->json('blocks.0.content.cards.0'))->toMatchArray(['title' => '0–2 বছর', 'line' => 'Sensory & first play', 'href' => '/toys?age=0-12,12-24', 'tone' => 'yellow'])
        ->and(CmsKit::home('en')->json('blocks.0.content.cards.0.image.alt'))->toBe('Baby with a ball')
        ->and($bn->json('blocks.0.content.cards.0'))->not->toHaveKey('media_id')
        ->and($bn->json('blocks.0.content.cards.1'))->toMatchArray(['tone' => 'green', 'image' => null])
        ->and($bn->json('blocks.0.age_counts'))->toBeArray();
});

it('rejects an age card without a link, a tone off the palette and a ninth card', function (): void {
    CmsKit::actAsEditor();
    $card = ['title' => '0–2', 'href' => '/toys'];

    expect(sjErrors($this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'age_tiles', 'content' => ['title' => 'Age', 'cards' => [['title' => 'x']]]])))->toContain('content.cards.0.href')
        ->and(sjErrors($this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'age_tiles', 'content' => ['title' => 'Age', 'eyebrow_tone' => 'teal']])))->toContain('content.eyebrow_tone')
        ->and(sjErrors($this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'age_tiles', 'content' => ['title' => 'Age', 'cards' => array_fill(0, 9, $card)]])))->toContain('content.cards');
});

it('sends the rail header, layout, band and button wording, and runs the rule inside each chip category (34:5742)', function (): void {
    $plush = Category::factory()->create(['name' => 'Soft toys', 'name_bn' => 'সফট টয়', 'slug' => 'soft-toys']);
    $books = Category::factory()->create(['name' => 'Books', 'slug' => 'books']);
    $empty = Category::factory()->create(['name' => 'Empty', 'slug' => 'empty']);
    CmsKit::product('bunny', 3, ['primary_category_id' => $plush->id]);
    CmsKit::product('atlas', 3, ['primary_category_id' => $books->id]);

    CmsKit::block('product_rail', [
        'eyebrow' => 'Most loved', 'eyebrow_bn' => 'সবচেয়ে প্রিয়', 'eyebrow_tone' => 'yellow', 'title' => 'Bought again and again', 'title_bn' => null, 'sub' => 'Played with for months.', 'sub_bn' => null,
        'layout' => 'grid', 'band' => 'pink', 'see_all_label' => 'See all best sellers', 'see_all_label_bn' => null, 'all_label' => 'All', 'all_label_bn' => 'সব',
        'rule' => 'new', 'chip_category_ids' => [$plush->id, $empty->id, $books->id],
    ]);
    sjPublish();

    $bn = CmsKit::home('bn')->assertOk();
    expect($bn->json('blocks.0.content'))->toBe([
        'eyebrow' => 'সবচেয়ে প্রিয়', 'eyebrow_tone' => 'yellow', 'title' => 'Bought again and again', 'sub' => 'Played with for months.',
        'layout' => 'grid', 'band' => 'pink', 'see_all_label' => 'See all best sellers', 'all_label' => 'সব', 'limit' => 8,
        'slider' => ['loop' => false, 'autoplay' => false, 'delay' => 5, 'speed' => 500],
    ])
        ->and($bn->json('blocks.0.products.*.slug'))->toEqualCanonicalizing(['bunny', 'atlas'])
        // The empty category's chip is left out; each chip carries its own products, in the locale.
        ->and($bn->json('blocks.0.chips.*.slug'))->toBe(['soft-toys', 'books'])
        ->and($bn->json('blocks.0.chips.0.name'))->toBe('সফট টয়')
        ->and($bn->json('blocks.0.chips.0.products.*.slug'))->toBe(['bunny'])
        ->and($bn->json('blocks.0.chips.1.products.*.slug'))->toBe(['atlas']);

    CmsKit::assertStockInvariant();
});

it('keeps an old rail (title only) answering with a slider and no chips', function (): void {
    CmsKit::product('stacker', 2);
    CmsKit::block('product_rail', ['title' => 'New', 'rule' => 'new']);
    sjPublish();

    expect(CmsKit::home()->json('blocks.0'))->toMatchArray(['chips' => []])
        ->and(CmsKit::home()->json('blocks.0.content.layout'))->toBe('slider')
        ->and(CmsKit::home()->json('blocks.0.content.band'))->toBeNull();
});

it('flags a product card as new for the configured days after publishing', function (): void {
    config(['catalog.new_badge_days' => 30]);
    CmsKit::product('fresh', 2, ['published_at' => now()->subDays(3)]);
    CmsKit::product('older', 2, ['published_at' => now()->subDays(45)]);
    CmsKit::block('product_rail', ['title' => 'New', 'rule' => 'new']);
    sjPublish();

    $cards = collect(CmsKit::home()->json('blocks.0.products'))->keyBy('slug');
    expect($cards['fresh']['is_new'])->toBeTrue()->and($cards['older']['is_new'])->toBeFalse();
});

it('renders promo banners with their images and tones, and drops nothing the editor set (34:5763)', function (): void {
    $image = Media::factory()->create();
    CmsKit::block('promo_banners', ['items' => [
        ['overline' => 'Gift shop', 'title' => 'Birthday boxes', 'title_bn' => 'জন্মদিনের বক্স', 'body' => 'From ৳990.', 'cta_label' => 'Shop gifts', 'cta_href' => '/toys', 'media_id' => $image->id],
        ['title' => 'Montessori corner', 'cta_href' => null, 'tone' => 'green'],
    ]]);
    sjPublish();

    $bn = CmsKit::home('bn')->assertOk();
    expect(CmsKit::types($bn))->toBe(['promo_banners'])
        ->and($bn->json('blocks.0.content.items.0'))->toMatchArray(['overline' => 'Gift shop', 'title' => 'জন্মদিনের বক্স', 'cta_href' => '/toys', 'tone' => 'purple'])
        ->and($bn->json('blocks.0.content.items.0.image.url'))->toBeString()
        ->and($bn->json('blocks.0.content.items.1'))->toMatchArray(['title' => 'Montessori corner', 'tone' => 'green', 'image' => null]);
});

it('shows the owner-typed figures of the why band exactly as typed, never defaulted (34:5786)', function (): void {
    $photo = Media::factory()->create();
    expect(BlockType::WhyBand->schema()->defaults()['stats'])->toBe([]);

    CmsKit::block('why_band', [
        'eyebrow' => 'Why parents choose us', 'title' => 'Chosen for how it helps a child grow', 'body' => 'Tested for safety.',
        'stats' => [['value' => '50,000+', 'label' => 'happy families', 'label_bn' => 'সুখী পরিবার'], ['value' => '  ', 'label' => 'blank figure is dropped']],
        'media_id' => $photo->id, 'alt' => 'Boy building',
    ]);
    sjPublish();

    $bn = CmsKit::home('bn')->assertOk();
    expect($bn->json('blocks.0.content.stats'))->toBe([['value' => '50,000+', 'label' => 'সুখী পরিবার']])
        ->and($bn->json('blocks.0.content.image.alt'))->toBe('Boy building');
});

it('renders the video reel: posters, typed captions, and a product chip only for a listable product (55:12790)', function (): void {
    [$poster, $gone] = [Media::factory()->create(), Media::factory()->create()];
    $listed = CmsKit::product('rainbow-stacker', 2, ['name' => 'Wooden Rainbow Stacker', 'name_bn' => 'রংধনু স্ট্যাকার']);
    $hidden = CmsKit::product('hidden', 0, ['published_at' => now()->addWeek()]);

    CmsKit::block('video_reel', [
        'title' => 'Toys in action', 'title_bn' => 'খেলার মুহূর্ত', 'band' => 'purple', 'see_all_label' => 'See all', 'see_all_href' => '/blog',
        'items' => [
            ['title' => 'A rainbow tower in a minute', 'video_url' => 'https://youtube.com/shorts/Abc_def-123', 'media_id' => $poster->id, 'duration' => '0:42', 'views' => '12.4k views', 'views_bn' => '12.4k বার দেখা', 'product_id' => $listed->id],
            ['title' => 'Unlisted product', 'video_url' => 'https://www.instagram.com/reel/C9xYz12AbcD/', 'media_id' => $poster->id, 'product_id' => $hidden->id],
            ['title' => 'Poster deleted', 'video_url' => 'https://cdn.example.com/reels/tower.mp4', 'media_id' => $gone->id],
        ],
    ]);
    $gone->delete();
    sjPublish();

    $bn = CmsKit::home('bn')->assertOk();
    expect($bn->json('blocks.0.content'))->toMatchArray(['title' => 'খেলার মুহূর্ত', 'eyebrow_tone' => 'pink', 'band' => 'purple', 'see_all_href' => '/blog'])
        ->and($bn->json('blocks.0.content.items'))->toHaveCount(2)
        ->and($bn->json('blocks.0.content.items.0'))->toMatchArray(['video_url' => 'https://youtube.com/shorts/Abc_def-123', 'duration' => '0:42', 'views' => '12.4k বার দেখা', 'product' => ['name' => 'রংধনু স্ট্যাকার', 'slug' => 'rainbow-stacker']])
        ->and($bn->json('blocks.0.content.items.0.poster.url'))->toBeString()
        ->and($bn->json('blocks.0.content.items.0.embed'))->toBe(['kind' => 'youtube', 'src' => 'https://www.youtube-nocookie.com/embed/Abc_def-123?autoplay=1&playsinline=1&rel=0'])
        ->and($bn->json('blocks.0.content.items.1.embed'))->toBe(['kind' => 'instagram', 'src' => 'https://www.instagram.com/reel/C9xYz12AbcD/embed'])
        ->and($bn->json('blocks.0.content.items.0'))->not->toHaveKeys(['media_id', 'product_id'])
        ->and($bn->json('blocks.0.content.items.1.product'))->toBeNull();

    CmsKit::assertStockInvariant();
});

it('removes a video reel with no videos', function (): void {
    CmsKit::block('video_reel');
    CmsKit::block('faq');
    sjPublish();
    expect(CmsKit::types(CmsKit::home()))->toBe(['faq']);

});

it('refuses an http or script video link, a reel without a poster and a malformed length', function (): void {
    CmsKit::actAsEditor();
    $poster = Media::factory()->create();

    expect(sjErrors($this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'video_reel', 'content' => ['title' => 'Reel', 'items' => [['title' => 'x', 'video_url' => 'http://example.com/v']]]])))
        ->toContain('content.items.0.video_url', 'content.items.0.media_id')
        ->and(sjErrors($this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'video_reel', 'content' => ['title' => 'Reel', 'items' => [['title' => 'x', 'video_url' => 'javascript:alert(1)', 'media_id' => $poster->id, 'duration' => 'long']]]])))
        ->toContain('content.items.0.video_url', 'content.items.0.duration')
        // https, but no player for it: a channel page, a Facebook profile, a TikTok link.
        ->and(sjErrors($this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'video_reel', 'content' => ['title' => 'Reel', 'items' => [['title' => 'x', 'video_url' => 'https://www.youtube.com/@smartjunior', 'media_id' => $poster->id]]]])))
        ->toContain('content.items.0.video_url')
        ->and(sjErrors($this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'video_reel', 'content' => ['title' => 'Reel', 'items' => [['title' => 'x', 'video_url' => 'https://www.tiktok.com/@a/video/1', 'media_id' => $poster->id]]]])))
        ->toContain('content.items.0.video_url');
});

it('sends the journal eyebrow and its see-all link, and the help card tone and hotline switch (34:5851, 34:5872)', function (): void {
    BlogPost::query()->create(['type' => PostType::Article, 'slug' => 'soft-ball', 'title' => 'Five games', 'status' => PostStatus::Published, 'published_at' => now()->subDay(), 'reading_minutes' => 4]);
    CmsKit::block('blog_teaser', ['eyebrow' => 'For parents', 'eyebrow_bn' => 'অভিভাবকদের জন্য', 'title' => 'Play & learn journal', 'show_see_all' => true]);
    CmsKit::block('chat_band', ['title' => 'Not sure what to pick?', 'tone' => 'yellow', 'show_hotline' => true]);
    sjPublish();

    $bn = CmsKit::home('bn')->assertOk();
    expect($bn->json('blocks.0.content'))->toMatchArray(['eyebrow' => 'অভিভাবকদের জন্য', 'eyebrow_tone' => 'green'])
        ->and($bn->json('blocks.0.see_all_href'))->toBe('/blog')
        ->and($bn->json('blocks.1.content'))->toMatchArray(['tone' => 'yellow', 'show_hotline' => true]);
});

it('records the images of age cards, promo banners and reel posters as block media', function (): void {
    [$card, $banner, $poster] = [Media::factory()->create(), Media::factory()->create(), Media::factory()->create()];
    CmsKit::actAsEditor();
    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'age_tiles', 'content' => ['title' => 'Age', 'cards' => [['title' => '0–2', 'href' => '/toys', 'media_id' => $card->id]]]])->assertCreated();
    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'promo_banners', 'content' => ['items' => [['title' => 'Gifts', 'media_id' => $banner->id]]]])->assertCreated();
    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'video_reel', 'content' => ['title' => 'Reel', 'items' => [['title' => 'x', 'video_url' => 'https://youtu.be/Abc_def-123', 'media_id' => $poster->id]]]])->assertCreated();

    expect(DB::table('cms_block_media')->where('layer', 'draft')->pluck('media_id')->sort()->values()->all())->toBe([$card->id, $banner->id, $poster->id]);
});

it('takes slider settings on the four slider blocks: loop, autoplay, delay and speed from fixed choices', function (): void {
    CmsKit::actAsEditor();
    $bad = ['loop' => 'sometimes', 'delay' => 7, 'speed' => 999];
    foreach (['hero' => ['slides' => [['headline' => 'Hi']]], 'product_rail' => ['title' => 'New', 'rule' => 'new'], 'flash_sale' => ['title' => 'Flash'], 'video_reel' => ['title' => 'Reels']] as $type => $content) {
        expect(sjErrors($this->postJson(CmsKit::ADMIN.'/blocks', ['type' => $type, 'content' => [...$content, 'slider' => $bad]])))
            ->toContain('content.slider.loop', 'content.slider.delay', 'content.slider.speed');
    }
});

it('sends the saved slider settings, and the defaults for a block saved before they existed', function (): void {
    CmsKit::product('stacker', 2);
    CmsKit::block('product_rail', ['title' => 'New', 'rule' => 'new', 'slider' => ['loop' => true, 'autoplay' => true, 'delay' => 8, 'speed' => 800]]);
    $old = CmsKit::block('flash_sale', ['title' => 'Flash']);
    $old->forceFill(['content' => array_diff_key($old->content, ['slider' => true])])->save();
    sjPublish();

    expect(CmsKit::home()->json('blocks.0.content.slider'))->toBe(['loop' => true, 'autoplay' => true, 'delay' => 8, 'speed' => 800])
        ->and(CmsKit::home()->json('blocks.1.content.slider'))->toBe(App\Domain\Content\Blocks\Schemas\BlockSchema::sliderDefaults());
});
