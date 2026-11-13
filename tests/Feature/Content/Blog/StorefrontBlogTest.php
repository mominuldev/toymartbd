<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Content\Blog\Actions\PublishDueBlogPosts;
use App\Domain\Content\Blog\Enums\PostStatus;
use App\Domain\Content\Blog\Enums\PostType;
use App\Domain\Content\Blog\Models\BlogPostProduct;
use App\Domain\Content\Blog\Models\BlogPostSection;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Support\PreviewToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Content\Blog\BlogKit;

/*
 * Storefront blog reads (Phase 7): live posts only, pagination, taxonomy, scheduled publish
 * at the Dhaka boundary, related posts, buying-guide picks hidden when unbuyable, preview.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

function blogGet(string $path, string $locale = 'en'): TestResponse
{
    return test()->withToken(BlogKit::token())->withHeader('Accept-Language', $locale)->getJson('/api/v1/storefront/blog/'.$path);
}

it('lists live posts newest first with numbered pages', function (): void {
    foreach (range(1, 14) as $i) {
        BlogKit::post(['slug' => "p-{$i}", 'published_at' => Carbon::now()->subDays(20 - $i)]);
    }
    BlogKit::post(['slug' => 'draft', 'status' => PostStatus::Draft, 'published_at' => null]);
    BlogKit::post(['slug' => 'future', 'status' => PostStatus::Scheduled, 'published_at' => Carbon::now()->addDay()]);

    $first = blogGet('posts?per_page=12')->assertOk();
    expect($first->json('data.0.slug'))->toBe('p-14')
        ->and($first->json('meta.current_page'))->toBe(1)
        ->and($first->json('meta.last_page'))->toBe(2)
        ->and($first->json('meta.total'))->toBe(14)
        ->and(array_keys($first->json('data.0')))->toBe([
            'id', 'type', 'slug', 'title', 'title_bn', 'excerpt', 'excerpt_bn', 'cover', 'category', 'author',
            'reading_minutes', 'reading_minutes_bn', 'published_at', 'is_featured',
        ]);

    expect(blogGet('posts?per_page=12&page=2')->assertOk()->json('data.*.slug'))->toBe(['p-2', 'p-1']);
    blogGet('posts?per_page=99')->assertStatus(400);
    blogGet('posts?page=0')->assertStatus(400);
    blogGet('posts?filter[views]=1')->assertStatus(400);
});

it('serves both locales from one slug with Bangla fields alongside English', function (string $locale): void {
    BlogKit::post(['slug' => 'shared-slug', 'title' => 'Blocks', 'title_bn' => 'ব্লক']);

    blogGet('posts/shared-slug', $locale)->assertOk()
        ->assertJsonPath('slug', 'shared-slug')->assertJsonPath('title', 'Blocks')->assertJsonPath('title_bn', 'ব্লক');
})->with(['en', 'bn']);

it('filters by category, tag, author and type, and shows each term with its live post count', function (): void {
    $category = BlogKit::category('parenting');
    $tag = BlogKit::tag('stem');
    $author = BlogKit::author('rina');
    $a = BlogKit::post(['slug' => 'a', 'category_id' => $category->id, 'author_id' => $author->id]);
    $a->tags()->attach($tag->id);
    BlogKit::post(['slug' => 'b', 'type' => PostType::BuyingGuide]);
    BlogKit::post(['slug' => 'c', 'category_id' => $category->id, 'status' => PostStatus::Draft, 'published_at' => null]);

    expect(blogGet('posts?filter[category]=parenting')->json('data.*.slug'))->toBe(['a'])
        ->and(blogGet('posts?filter[tag]=stem')->json('data.*.slug'))->toBe(['a'])
        ->and(blogGet('posts?filter[author]=rina')->json('data.*.slug'))->toBe(['a'])
        ->and(blogGet('posts?filter[type]=buying_guide')->json('data.*.slug'))->toBe(['b']);

    blogGet('categories/parenting')->assertOk()->assertJsonPath('posts_count', 1);
    blogGet('authors/rina')->assertOk()->assertJsonPath('description', 'Parent of two.');
    blogGet('tags/nope')->assertNotFound();
    expect(blogGet('categories')->json('data.*.slug'))->toBe(['parenting']);
});

it('goes live exactly at the scheduled Dhaka instant, and the sweep publishes and purges', function (): void {
    Queue::fake();
    // 1 Dec 2026 00:00 Dhaka = 30 Nov 2026 18:00 UTC.
    $post = BlogKit::post(['slug' => 'midnight', 'status' => PostStatus::Scheduled, 'published_at' => Carbon::parse('2026-11-30 18:00:00', 'UTC')]);

    Carbon::setTestNow(Carbon::parse('2026-11-30 17:59:59', 'UTC'));
    blogGet('posts/midnight')->assertNotFound();
    expect(app(PublishDueBlogPosts::class)->handle())->toBe([]);

    Carbon::setTestNow(Carbon::parse('2026-11-30 18:00:00', 'UTC'));
    blogGet('posts/midnight')->assertOk();
    expect(app(PublishDueBlogPosts::class)->handle())->toBe([$post->id])
        ->and($post->refresh()->status)->toBe(PostStatus::Published)
        ->and(app(PublishDueBlogPosts::class)->handle())->toBe([]);

    Queue::assertPushed(RevalidateStorefrontTags::class, fn ($job): bool => $job->tags === ['content:blog', 'content:post:'.$post->id, 'post:midnight']);
    $this->artisan('toymart:blog:publish-due')->assertSuccessful();
});

it('ranks related posts by shared tags and category, then recency, and fills with recent posts', function (): void {
    $category = BlogKit::category('toys');
    [$t1, $t2] = [BlogKit::tag('lego'), BlogKit::tag('stem')];
    $main = BlogKit::post(['slug' => 'main', 'category_id' => $category->id]);
    $main->tags()->attach([$t1->id, $t2->id]);

    $twoTags = BlogKit::post(['slug' => 'two-tags', 'published_at' => Carbon::now()->subDays(30)]);
    $twoTags->tags()->attach([$t1->id, $t2->id]);
    $catAndTag = BlogKit::post(['slug' => 'cat-and-tag', 'category_id' => $category->id, 'published_at' => Carbon::now()->subDays(40)]);
    $catAndTag->tags()->attach($t1->id);
    BlogKit::post(['slug' => 'unrelated-new', 'published_at' => Carbon::now()->subHour()]);
    $hidden = BlogKit::post(['slug' => 'draft-related', 'category_id' => $category->id, 'status' => PostStatus::Draft, 'published_at' => null]);
    $hidden->tags()->attach([$t1->id, $t2->id]);

    expect(blogGet('posts/main')->assertOk()->json('related_posts.*.slug'))->toBe(['cat-and-tag', 'two-tags', 'unrelated-new']);
});

it('hides buying-guide picks and related products that are out of stock, archived or unpublished', function (): void {
    $guide = BlogKit::post(['slug' => 'guide', 'type' => PostType::BuyingGuide]);
    $section = BlogPostSection::query()->create(['post_id' => $guide->id, 'heading' => 'Under ৳1,000', 'heading_bn' => '৳1,000-এর নিচে', 'body' => '<p>ok</p><script>bad()</script>']);

    $stocked = BlogKit::stockedProduct('stocked', 5, '650.00');
    $soldOut = BlogKit::stockedProduct('sold-out', 0);
    $archived = BlogKit::stockedProduct('archived-toy', 3);
    $archived->update(['status' => ProductStatus::Archived]);
    $unpublished = BlogKit::stockedProduct('later-toy', 3);
    $unpublished->update(['published_at' => Carbon::now()->addWeek()]);

    foreach ([$soldOut, $stocked, $archived, $unpublished] as $i => $product) {
        BlogPostProduct::query()->create(['post_id' => $guide->id, 'section_id' => $section->id, 'product_id' => $product->id, 'position' => $i, 'note' => 'n'.$i]);
    }
    BlogPostProduct::query()->create(['post_id' => $guide->id, 'product_id' => $soldOut->id, 'position' => 0]);
    BlogPostProduct::query()->create(['post_id' => $guide->id, 'product_id' => $stocked->id, 'position' => 1]);

    $response = blogGet('posts/guide')->assertOk();
    expect($response->json('sections.0.picks.*.product.slug'))->toBe(['stocked'])
        ->and($response->json('sections.0.picks.0.note'))->toBe('n1')
        ->and($response->json('sections.0.picks.0.product.price'))->toBe('650.00')
        ->and($response->json('sections.0.picks.0.product.in_stock'))->toBeTrue()
        ->and($response->json('sections.0.body'))->toBe('<p>ok</p>')
        ->and($response->json('related_products.*.product.slug'))->toBe(['stocked']);

    BlogKit::assertStockInvariant();
});

it('re-sanitises stored body HTML on read', function (): void {
    BlogKit::post(['slug' => 'legacy', 'body' => '<p>Hi</p><script>alert(1)</script><img src="https://evil.test/x.png" onerror="x()">']);

    expect(blogGet('posts/legacy')->json('body'))->toBe('<p>Hi</p>');
});

it('serves the home-page teaser: featured first, then newest, capped', function (): void {
    BlogKit::post(['slug' => 'old-featured', 'is_featured' => true, 'published_at' => Carbon::now()->subMonth()]);
    BlogKit::post(['slug' => 'newest', 'published_at' => Carbon::now()->subMinute()]);
    BlogKit::post(['slug' => 'older', 'published_at' => Carbon::now()->subWeek()]);
    BlogKit::post(['slug' => 'oldest', 'published_at' => Carbon::now()->subWeeks(2)]);

    expect(blogGet('teaser')->json('data.*.slug'))->toBe(['old-featured', 'newest', 'older'])
        ->and(blogGet('teaser?limit=50')->json('data'))->toHaveCount(4);
});

it('refuses forged, wrong-type and expired preview tokens', function (): void {
    $draft = BlogKit::post(['status' => PostStatus::Draft, 'published_at' => null]);
    $token = PreviewToken::issue('blog_post', $draft->id);

    blogGet('preview/'.substr($token, 0, -1).(str_ends_with($token, '0') ? '1' : '0'))->assertNotFound();
    blogGet('preview/'.PreviewToken::issue('cms_page', $draft->id))->assertNotFound();
    blogGet('preview/'.$token)->assertOk();

    Carbon::setTestNow(Carbon::now()->addSeconds(PreviewToken::DEFAULT_TTL_SECONDS + 1));
    blogGet('preview/'.$token)->assertNotFound();
});
