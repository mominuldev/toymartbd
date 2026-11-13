<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Content\Models\MenuItem;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Menu builder (Phase 7): nesting caps per location, reorder by save, link resolution, the
 * storefront read and its category-tree fallback, and revalidation.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::ContentEditor)->create()->refresh(), 'web');
});

function scMenuToken(): string
{
    return User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
}

/** @return array<string, mixed> */
function scItem(string $label, string $type = 'none', ?int $id = null, ?string $url = null, array $children = [], bool $visible = true): array
{
    return ['label' => $label, 'label_bn' => $label.' (বাংলা)', 'link_type' => $type, 'link_id' => $id, 'url' => $url, 'is_visible' => $visible, 'children' => $children];
}

it('answers every location, unsaved ones empty', function (): void {
    $this->getJson('/api/v1/admin/content/menus')
        ->assertOk()
        ->assertJsonPath('data.*.location', ['header', 'footer', 'mobile'])
        ->assertJsonPath('data.0.exists', false)
        ->assertJsonPath('data.0.max_depth', 3)
        ->assertJsonPath('data.1.max_depth', 2);
});

it('saves a nested footer with resolved targets, in order', function (): void {
    $brand = Brand::factory()->create(['name' => 'LEGO', 'slug' => 'lego']);
    $page = CmsPage::query()->create(['slug' => 'returns', 'title' => 'Returns', 'status' => 'published', 'published_at' => now()->subDay()]);

    $this->putJson('/api/v1/admin/content/menus/footer', ['is_published' => true, 'items' => [
        scItem('Help', children: [scItem('Returns', 'page', $page->id), scItem('Track', 'custom', url: '/track')]),
        scItem('Shop', children: [scItem('LEGO', 'brand', $brand->id)]),
    ]])
        ->assertOk()
        ->assertJsonPath('is_published', true)
        ->assertJsonPath('items.0.label', 'Help')
        ->assertJsonPath('items.0.children.0.target.path', '/pages/returns')
        ->assertJsonPath('items.0.children.0.target.live', true)
        ->assertJsonPath('items.0.children.1.url', '/track')
        ->assertJsonPath('items.1.children.0.target.path', '/brands/lego');

    // Reorder = save the new order.
    $this->putJson('/api/v1/admin/content/menus/footer', ['is_published' => true, 'items' => [
        scItem('Shop', children: [scItem('LEGO', 'brand', $brand->id)]),
        scItem('Help', children: [scItem('Track', 'custom', url: '/track'), scItem('Returns', 'page', $page->id)]),
    ]])->assertOk()->assertJsonPath('items.*.label', ['Shop', 'Help'])->assertJsonPath('items.1.children.*.label', ['Track', 'Returns']);

    expect(MenuItem::query()->count())->toBe(5);
});

it('caps nesting depth per location', function (): void {
    $deep = [scItem('A', children: [scItem('B', children: [scItem('C', 'custom', url: '/c')])])];

    $this->putJson('/api/v1/admin/content/menus/footer', ['is_published' => true, 'items' => $deep])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'items.0.children.0.children.0');

    $this->putJson('/api/v1/admin/content/menus/header', ['is_published' => true, 'items' => $deep])->assertOk();

    $this->putJson('/api/v1/admin/content/menus/header', ['is_published' => true, 'items' => [
        scItem('A', children: [scItem('B', children: [scItem('C', children: [scItem('D', 'custom', url: '/d')])])]),
    ]])->assertUnprocessable();
});

it('validates link targets, custom URLs and the category tree slot', function (): void {
    $this->putJson('/api/v1/admin/content/menus/header', ['is_published' => true, 'items' => [scItem('Ghost', 'brand', 999999)]])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'items.0.link_id');
    $this->putJson('/api/v1/admin/content/menus/header', ['is_published' => true, 'items' => [scItem('Bad', 'custom', url: 'javascript:alert(1)')]])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'items.0.url');
    $this->putJson('/api/v1/admin/content/menus/header', ['is_published' => true, 'items' => [scItem('Bad', 'custom', url: '//evil.test')]])
        ->assertUnprocessable();
    $this->putJson('/api/v1/admin/content/menus/header', ['is_published' => true, 'items' => [scItem('Shop', children: [scItem('All', 'category_tree')])]])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'items.0.children.0.link_type');
});

it('serves published menus only, hiding hidden items and dead targets; null means fall back', function (): void {
    $live = Category::factory()->create(['name' => 'Puzzles', 'slug' => 'puzzles']);
    $archived = Category::factory()->create(['name' => 'Old', 'slug' => 'old', 'status' => 'archived']);
    $draft = CmsPage::query()->create(['slug' => 'draft', 'title' => 'Draft', 'status' => 'draft']);

    $this->putJson('/api/v1/admin/content/menus/header', ['is_published' => true, 'items' => [
        scItem('Shop all', 'category_tree'),
        scItem('Puzzles', 'category', $live->id),
        scItem('Old', 'category', $archived->id),
        scItem('Draft', 'page', $draft->id),
        scItem('Hidden', 'custom', url: '/hidden', visible: false),
        scItem('Empty heading', children: [scItem('Old', 'category', $archived->id)]),
    ]])->assertOk();
    $this->putJson('/api/v1/admin/content/menus/footer', ['is_published' => false, 'items' => [scItem('Help', children: [scItem('Track', 'custom', url: '/track')])]])->assertOk();

    $this->withToken(scMenuToken())->getJson('/api/v1/storefront/menus')
        ->assertOk()
        ->assertJsonPath('footer', null)
        ->assertJsonPath('mobile', null)
        ->assertJsonPath('header.items', [
            ['label' => 'Shop all', 'label_bn' => 'Shop all (বাংলা)', 'type' => 'category_tree', 'href' => '/categories', 'open_in_new_tab' => false, 'children' => []],
            ['label' => 'Puzzles', 'label_bn' => 'Puzzles (বাংলা)', 'type' => 'category', 'href' => '/categories/puzzles', 'open_in_new_tab' => false, 'children' => []],
        ]);
});

it('searches link targets for the picker', function (): void {
    Brand::factory()->create(['name' => 'LEGO', 'slug' => 'lego']);
    Brand::factory()->create(['name' => 'Barbie', 'slug' => 'barbie']);

    $this->getJson('/api/v1/admin/content/menu-targets?type=brand&search=leg')
        ->assertOk()->assertJsonPath('data.*.path', ['/brands/lego']);
    $this->getJson('/api/v1/admin/content/menu-targets?type=custom')->assertStatus(400);
});

it('purges content:menus after a save', function (): void {
    Queue::fake();

    $this->putJson('/api/v1/admin/content/menus/mobile', ['is_published' => true, 'items' => [scItem('Track', 'custom', url: '/track')]])->assertOk();

    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['content:menus']);
});
