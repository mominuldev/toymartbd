<?php

declare(strict_types=1);

use App\Domain\Content\Blog\Enums\PostStatus;
use App\Models\Enums\Permission;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Content\Blog\BlogKit;
use Tests\Support\RoleMatrix;

/* 403 role matrix for every admin blog endpoint (CLAUDE.md rule 21): content.view / content.manage. */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

it('guards reading posts with content.view', function (): void {
    $post = BlogKit::post();
    RoleMatrix::assert($this, Permission::ContentView, fn () => $this->getJson('/api/v1/admin/blog/posts'));
    RoleMatrix::assert($this, Permission::ContentView, fn () => $this->getJson("/api/v1/admin/blog/posts/{$post->id}"));
});

it('guards writing posts with content.manage', function (): void {
    $post = BlogKit::post();
    $payload = ['type' => 'article', 'title' => 'Hello', 'status' => 'draft'];
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->postJson('/api/v1/admin/blog/posts', $payload), 201);
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->putJson("/api/v1/admin/blog/posts/{$post->id}", $payload));
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->postJson("/api/v1/admin/blog/posts/{$post->id}/preview-link"));
});

it('guards deleting a draft with content.manage', function (): void {
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->deleteJson('/api/v1/admin/blog/posts/'.BlogKit::post(['status' => PostStatus::Draft, 'published_at' => null])->id), 204);
});

it('guards categories, tags and authors', function (string $path): void {
    RoleMatrix::assert($this, Permission::ContentView, fn () => $this->getJson("/api/v1/admin/blog/{$path}"));
    $n = 0;
    RoleMatrix::assert($this, Permission::ContentManage, function () use ($path, &$n) {
        $n++;

        return $this->postJson("/api/v1/admin/blog/{$path}", ['name' => 'Term '.$n]);
    }, 201);
    $id = $this->postJson("/api/v1/admin/blog/{$path}", ['name' => 'Target'])->json('id');
    if ($id !== null) {
        RoleMatrix::assert($this, Permission::ContentView, fn () => $this->getJson("/api/v1/admin/blog/{$path}/{$id}"));
        RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->patchJson("/api/v1/admin/blog/{$path}/{$id}", ['name' => 'Renamed']));
    }
})->with(['categories', 'tags', 'authors']);

it('guards deleting terms with content.manage', function (string $path): void {
    $make = match ($path) {
        'categories' => fn () => BlogKit::category('c-'.uniqid()),
        'tags' => fn () => BlogKit::tag('t-'.uniqid()),
        default => fn () => BlogKit::author('a-'.uniqid()),
    };
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->deleteJson("/api/v1/admin/blog/{$path}/".$make()->id), 204);
})->with(['categories', 'tags', 'authors']);
