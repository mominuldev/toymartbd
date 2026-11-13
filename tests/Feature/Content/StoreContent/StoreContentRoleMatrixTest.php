<?php

declare(strict_types=1);

use App\Domain\Content\Models\Banner;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Content\Models\Testimonial;
use App\Models\Enums\Permission;
use Illuminate\Support\Facades\Http;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for every admin content endpoint: pages, menus, banners, testimonials
 * (CLAUDE.md rule 21). Reads need content.view, writes content.manage.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

function scPage(): CmsPage
{
    return CmsPage::query()->create(['slug' => 'about-'.uniqid(), 'title' => 'About', 'status' => 'draft']);
}

it('guards content reads with content.view', function (string $uri): void {
    $page = scPage();
    $banner = Banner::query()->create(['placement' => 'announcement_bar', 'name' => 'x', 'title' => 'x']);
    $testimonial = Testimonial::query()->create(['name' => 'A', 'quote' => 'Q']);

    RoleMatrix::assert($this, Permission::ContentView, fn () => $this->getJson(strtr($uri, ['{page}' => $page->id, '{banner}' => $banner->id, '{t}' => $testimonial->id])));
})->with([
    '/api/v1/admin/content/pages',
    '/api/v1/admin/content/pages/{page}',
    '/api/v1/admin/content/menus',
    '/api/v1/admin/content/menus/header',
    '/api/v1/admin/content/menu-targets?type=page',
    '/api/v1/admin/content/banners',
    '/api/v1/admin/content/banners/{banner}',
    '/api/v1/admin/content/testimonials',
    '/api/v1/admin/content/testimonials/{t}',
]);

it('guards page writes with content.manage', function (): void {
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->postJson('/api/v1/admin/content/pages', ['title' => 'P '.uniqid(), 'template' => 'default', 'status' => 'draft']), 201);
    $page = scPage();
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->putJson("/api/v1/admin/content/pages/{$page->id}", ['title' => 'Changed', 'template' => 'default', 'status' => 'draft']));
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->deleteJson("/api/v1/admin/content/pages/{$page->id}"));
});

it('guards menu saves with content.manage', function (): void {
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->putJson('/api/v1/admin/content/menus/footer', ['is_published' => true, 'items' => []]));
});

it('guards banner writes with content.manage', function (): void {
    $payload = ['placement' => 'announcement_bar', 'name' => 'n', 'title' => 't'];
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->postJson('/api/v1/admin/content/banners', $payload), 201);
    $banner = Banner::query()->firstOrFail();
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->putJson("/api/v1/admin/content/banners/{$banner->id}", $payload));
    RoleMatrix::assert($this, Permission::ContentManage, function () use ($payload) {
        $id = Banner::query()->create($payload)->id;

        return $this->deleteJson("/api/v1/admin/content/banners/{$id}");
    }, 204);
});

it('guards testimonial writes with content.manage', function (): void {
    $payload = ['name' => 'A', 'quote' => 'Q'];
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->postJson('/api/v1/admin/content/testimonials', $payload), 201);
    $t = Testimonial::query()->firstOrFail();
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->putJson("/api/v1/admin/content/testimonials/{$t->id}", $payload));
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->postJson('/api/v1/admin/content/testimonials/reorder', ['ids' => Testimonial::query()->orderByDesc('id')->pluck('id')->all()]));
    RoleMatrix::assert($this, Permission::ContentManage, function () use ($payload) {
        $id = Testimonial::query()->create($payload)->id;

        return $this->deleteJson("/api/v1/admin/content/testimonials/{$id}");
    }, 204);
});

it('refuses a guest with 401 and a token without storefront:read with 403', function (): void {
    $this->getJson('/api/v1/admin/content/pages')->assertUnauthorized();
    $this->getJson('/api/v1/storefront/menus')->assertUnauthorized();
    $token = App\Models\User::factory()->create(['is_active' => false])->createToken('x', ['other'])->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/storefront/testimonials')->assertForbidden();
});
