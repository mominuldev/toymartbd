<?php

declare(strict_types=1);

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Services\MediaUsages;
use App\Domain\Content\Blog\Enums\PostStatus;
use App\Domain\Content\Blog\Enums\PostType;
use App\Domain\Seo\Services\SeoSubjects;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Content\Blog\BlogKit;

/* Blog in the SEO module (subjects for panel, sitemap and redirect checks) and the media library. */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

it('registers posts and guides as one SEO type with per-type paths and live state', function (): void {
    $article = BlogKit::post(['slug' => 'an-article']);
    BlogKit::post(['slug' => 'a-guide', 'type' => PostType::BuyingGuide]);
    BlogKit::post(['slug' => 'a-draft', 'status' => PostStatus::Draft, 'published_at' => null]);

    $provider = app(SeoSubjects::class)->for('blog_post');
    expect($provider)->not->toBeNull()
        ->and($provider->find($article->id)?->path)->toBe('/blog/an-article')
        ->and($provider->findBySlug('a-draft')?->isLive)->toBeFalse()
        ->and(collect($provider->live())->pluck('path')->sort()->values()->all())->toBe(['/blog/an-article', '/guides/a-guide'])
        ->and($provider->livePaths(['/blog/an-article', '/blog/a-guide', '/guides/a-guide', '/blog/a-draft', '/products/x']))->toBe(['/blog/an-article', '/guides/a-guide']);
});

it('treats a category, tag or author archive as live only while it has a live post', function (): void {
    $used = BlogKit::category('used');
    BlogKit::category('empty');
    BlogKit::post(['category_id' => $used->id]);

    $provider = app(SeoSubjects::class)->for('blog_category');
    expect(collect($provider->live())->pluck('path')->all())->toBe(['/blog/category/used'])
        ->and($provider->livePaths(['/blog/category/used', '/blog/category/empty']))->toBe(['/blog/category/used'])
        ->and(app(SeoSubjects::class)->for('blog_tag'))->not->toBeNull()
        ->and(app(SeoSubjects::class)->for('blog_author'))->not->toBeNull();
});

it('reports a blog cover and an author avatar as media usages', function (): void {
    $cover = Media::factory()->create();
    $avatar = Media::factory()->create();
    $post = BlogKit::post(['title' => 'Cover story', 'cover_media_id' => $cover->id]);
    $author = BlogKit::author();
    $author->update(['avatar_media_id' => $avatar->id]);

    $usages = app(MediaUsages::class)->forMany([$cover->id, $avatar->id]);
    expect($usages[$cover->id][0]->type)->toBe('blog_cover')
        ->and($usages[$cover->id][0]->subjectId)->toBe($post->id)
        ->and($usages[$avatar->id][0]->type)->toBe('blog_author_avatar');
});

it('counts a blog cover as used so the library refuses to delete it', function (): void {
    $cover = Media::factory()->create();
    BlogKit::post(['cover_media_id' => $cover->id]);

    expect(app(MediaUsages::class)->isUsed($cover->id))->toBeTrue();
});
