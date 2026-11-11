<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for every admin products endpoint (CLAUDE.md rule 21).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

/**
 * A minimal simple-product document with no cost_price (so only the endpoint's own
 * permission decides), unique per call.
 *
 * @return array<string, mixed>
 */
function roleMatrixProductDocument(int $categoryId, ?string $price = '500'): array
{
    return [
        'name' => 'Matrix Toy '.uniqid(),
        'type' => 'simple',
        'status' => 'active',
        'primary_category_id' => $categoryId,
        'category_ids' => [$categoryId],
        'variants' => [['price' => $price]],
    ];
}

it('guards GET /products with catalog.view', function (): void {
    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->getJson('/api/v1/admin/products'));
});

it('guards GET /products/{product} with catalog.view', function (): void {
    $product = Product::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->getJson("/api/v1/admin/products/{$product->id}"));
});

it('guards POST /products with catalog.create', function (): void {
    $category = Category::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogCreate, fn () => $this->postJson('/api/v1/admin/products', roleMatrixProductDocument($category->id)), 201);

    $allowed = collect(Role::cases())->filter(fn (Role $role): bool => RoleMatrix::allows($role, Permission::CatalogCreate))->count();
    expect(Product::query()->count())->toBe($allowed);
});

it('guards PUT /products/{product} with catalog.update', function (): void {
    $product = Product::factory()->priced('500.00')->create();
    $document = roleMatrixProductDocument($product->primary_category_id);

    RoleMatrix::assert($this, Permission::CatalogUpdate, fn () => $this->putJson("/api/v1/admin/products/{$product->id}", $document));
});

it('guards a price change on PUT /products/{product} with catalog.update_price', function (): void {
    $product = Product::factory()->priced('500.00')->create();

    RoleMatrix::assert($this, Permission::CatalogUpdatePrice, function () use ($product) {
        $price = (string) random_int(600, 999);

        return $this->putJson("/api/v1/admin/products/{$product->id}", roleMatrixProductDocument($product->primary_category_id, $price));
    });
});

it('guards cost_price on POST /products with catalog.view_cost', function (): void {
    $category = Category::factory()->create();

    foreach (Role::cases() as $role) {
        $allowed = RoleMatrix::allows($role, Permission::CatalogCreate) && RoleMatrix::allows($role, Permission::CatalogViewCost);
        RoleMatrix::assertFor($this, $role, $allowed, function () use ($category) {
            $document = roleMatrixProductDocument($category->id);
            $document['variants'][0]['cost_price'] = '300';

            return $this->postJson('/api/v1/admin/products', $document);
        }, 201);
    }
});

it('leaves a product untouched for a role without catalog.update', function (): void {
    $product = Product::factory()->create(['name' => 'Before']);

    RoleMatrix::assertFor($this, Role::Viewer, false, fn () => $this->putJson("/api/v1/admin/products/{$product->id}", roleMatrixProductDocument($product->primary_category_id)));

    expect($product->refresh()->name)->toBe('Before');
});

it('guards DELETE /products/{product} with catalog.archive', function (): void {
    $product = Product::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogArchive, fn () => $this->deleteJson("/api/v1/admin/products/{$product->id}"), 204);

    expect($product->refresh()->isActive())->toBeFalse();
});

it('leaves a product active for a role without catalog.archive', function (): void {
    $product = Product::factory()->create();

    RoleMatrix::assertFor($this, Role::ContentEditor, false, fn () => $this->deleteJson("/api/v1/admin/products/{$product->id}"));

    expect($product->refresh()->isActive())->toBeTrue();
});

it('refuses a guest with 401 on every admin products endpoint', function (string $method, string $uri): void {
    $product = Product::factory()->create();

    $this->json($method, str_replace('{id}', (string) $product->id, $uri), ['name' => 'X'])
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/unauthenticated');
})->with([
    ['GET', '/api/v1/admin/products'],
    ['POST', '/api/v1/admin/products'],
    ['GET', '/api/v1/admin/products/{id}'],
    ['PUT', '/api/v1/admin/products/{id}'],
    ['DELETE', '/api/v1/admin/products/{id}'],
]);

it('guards POST /products/{product}/duplicate with catalog.create', function (): void {
    $product = Product::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogCreate, fn () => $this->postJson("/api/v1/admin/products/{$product->id}/duplicate"), 201);

    $allowed = collect(Role::cases())->filter(fn (Role $role): bool => RoleMatrix::allows($role, Permission::CatalogCreate))->count();
    expect(Product::query()->count())->toBe(1 + $allowed);
});

it('refuses a guest with 401 on duplicate', function (): void {
    $product = Product::factory()->create();

    $this->postJson("/api/v1/admin/products/{$product->id}/duplicate")->assertUnauthorized();
});
