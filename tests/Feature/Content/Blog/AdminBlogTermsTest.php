<?php

declare(strict_types=1);

use App\Domain\Content\Blog\Models\BlogCategory;
use App\Domain\Content\Blog\Models\BlogTag;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Seo\Models\SlugHistory;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Content\Blog\BlogKit;

/* Admin blog categories, tags and authors (Phase 7). */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::ContentEditor)->create()->refresh(), 'web');
});

it('creates, lists and edits a category, tag and author', function (string $path): void {
    $id = $this->postJson("/api/v1/admin/blog/{$path}", ['name' => 'Gift Guides', 'name_bn' => 'উপহার গাইড', 'description' => 'About'])
        ->assertCreated()->assertJsonPath('slug', 'gift-guides')->assertJsonPath('name_bn', 'উপহার গাইড')->json('id');

    $this->getJson("/api/v1/admin/blog/{$path}")->assertOk()->assertJsonPath('data.0.posts_count', 0);
    $this->patchJson("/api/v1/admin/blog/{$path}/{$id}", ['name' => 'Gifts'])->assertOk()
        ->assertJsonPath('name', 'Gifts')->assertJsonPath('slug', 'gift-guides');
    $this->patchJson("/api/v1/admin/blog/{$path}/{$id}", ['name' => 'Gifts', 'slug' => 'Not A Slug'])->assertUnprocessable();
})->with(['categories', 'tags', 'authors']);

it('refuses to delete a category or author still in use, and detaches a tag', function (): void {
    $category = BlogKit::category('guides');
    $author = BlogKit::author();
    $tag = BlogKit::tag('stem');
    $post = BlogKit::post(['category_id' => $category->id, 'author_id' => $author->id]);
    $post->tags()->attach($tag->id);

    $this->deleteJson("/api/v1/admin/blog/categories/{$category->id}")->assertUnprocessable();
    $this->deleteJson("/api/v1/admin/blog/authors/{$author->id}")->assertUnprocessable();

    Queue::fake();
    $this->deleteJson("/api/v1/admin/blog/tags/{$tag->id}")->assertNoContent();
    expect(BlogTag::query()->count())->toBe(0)->and($post->tags()->count())->toBe(0);
    Queue::assertPushed(RevalidateStorefrontTags::class, fn ($job): bool => $job->tags === ['content:blog', 'content:post:'.$post->id, 'post:'.$post->slug]);
});

it('writes slug history for a category with live posts and purges its posts', function (): void {
    Queue::fake();
    $category = BlogKit::category('old-cat');
    $post = BlogKit::post(['category_id' => $category->id]);

    $this->patchJson("/api/v1/admin/blog/categories/{$category->id}", ['name' => 'Cat', 'slug' => 'new-cat'])->assertOk();

    expect(SlugHistory::query()->where('sluggable_type', 'blog_category')->value('old_slug'))->toBe('old-cat')
        ->and(BlogCategory::query()->value('slug'))->toBe('new-cat');
    Queue::assertPushed(RevalidateStorefrontTags::class, fn ($job): bool => $job->tags === ['content:blog', 'content:post:'.$post->id, 'post:'.$post->slug]);
});
