<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
 * Storefront categories: the mega-menu tree and category pages (P4-CAT-08).
 * The tree shape is a contract with the Next.js mega menu — additive changes only.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

function categoryStorefrontToken(array $abilities = ['storefront:read']): string
{
    return User::factory()->create(['is_active' => false])->createToken('storefront', $abilities)->plainTextToken;
}

it('returns the menu tree in exactly the agreed shape, in tree order', function (): void {
    $toys = Category::factory()->create(['name' => 'Toys', 'name_bn' => 'খেলনা', 'slug' => 'toys', 'icon' => 'puzzle-piece']);
    $puzzles = Category::factory()->childOf($toys)->create(['slug' => 'puzzles']);
    Category::factory()->childOf($puzzles)->create(['slug' => 'jigsaw']);
    Category::factory()->childOf($toys)->create(['slug' => 'blocks']);
    Category::factory()->create(['slug' => 'books']);

    $response = $this->withToken(categoryStorefrontToken())->getJson('/api/v1/storefront/categories/tree')->assertOk();

    expect(array_keys($response->json()))->toBe(['data'])
        ->and(array_keys($response->json('data.0')))->toBe(['id', 'name', 'name_bn', 'slug', 'icon', 'image_url', 'children'])
        ->and($response->json('data.*.slug'))->toBe(['toys', 'books'])
        ->and($response->json('data.0.name_bn'))->toBe('খেলনা')
        ->and($response->json('data.0.icon'))->toBe('puzzle-piece')
        ->and($response->json('data.0.children.*.slug'))->toBe(['puzzles', 'blocks'])
        ->and($response->json('data.0.children.0.children.0.slug'))->toBe('jigsaw')
        ->and($response->json('data.0.children.0.children.0.children'))->toBe([])
        ->and($response->json('data.1.children'))->toBe([]);
});

it('hides archived and menu-hidden categories together with their branches', function (): void {
    $toys = Category::factory()->create(['slug' => 'toys']);
    $hidden = Category::factory()->childOf($toys)->hiddenFromMenu()->create(['slug' => 'hidden']);
    Category::factory()->childOf($hidden)->create(['slug' => 'under-hidden']);
    $archived = Category::factory()->archived()->create(['slug' => 'archived']);
    Category::factory()->childOf($archived)->archived()->create(['slug' => 'under-archived']);

    $response = $this->withToken(categoryStorefrontToken())->getJson('/api/v1/storefront/categories/tree')->assertOk();

    expect($response->json('data.*.slug'))->toBe(['toys'])
        ->and($response->json('data.0.children'))->toBe([]);
});

it('shows a category page with breadcrumbs and active children', function (): void {
    $toys = Category::factory()->create(['name' => 'Toys', 'name_bn' => 'খেলনা', 'slug' => 'toys']);
    $puzzles = Category::factory()->childOf($toys)->create(['name' => 'Puzzles', 'name_bn' => 'পাজল', 'slug' => 'puzzles']);
    Category::factory()->childOf($puzzles)->hiddenFromMenu()->create(['slug' => 'jigsaw']);
    Category::factory()->childOf($puzzles)->archived()->create(['slug' => 'old']);

    $response = $this->withToken(categoryStorefrontToken())->getJson('/api/v1/storefront/categories/puzzles')->assertOk();

    expect($response->json('slug'))->toBe('puzzles')
        ->and($response->json('breadcrumbs'))->toBe([
            ['name' => 'Toys', 'name_bn' => 'খেলনা', 'slug' => 'toys'],
            ['name' => 'Puzzles', 'name_bn' => 'পাজল', 'slug' => 'puzzles'],
        ])
        ->and($response->json('children.*.slug'))->toBe(['jigsaw'])
        ->and(array_keys($response->json('children.0')))->toBe(['id', 'name', 'name_bn', 'slug', 'icon', 'image_url', 'children']);
});

it('404s an archived or unknown category', function (): void {
    Category::factory()->archived()->create(['slug' => 'gone']);

    $this->withToken(categoryStorefrontToken())->getJson('/api/v1/storefront/categories/gone')->assertNotFound();
    $this->withToken(categoryStorefrontToken())->getJson('/api/v1/storefront/categories/nope')->assertNotFound();
});

it('requires a token with storefront:read', function (string $uri): void {
    Category::factory()->create(['slug' => 'toys']);

    $this->getJson($uri)->assertUnauthorized();
    $this->withToken(categoryStorefrontToken(['other']))->getJson($uri)->assertForbidden();
})->with(['/api/v1/storefront/categories/tree', '/api/v1/storefront/categories/toys']);
