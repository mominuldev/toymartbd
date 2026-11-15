<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for the barcode and label endpoints (CLAUDE.md rule 21).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

it('guards POST /products/{product}/barcodes with catalog.update', function (): void {
    $product = Product::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogUpdate, fn () => $this->postJson("/api/v1/admin/products/{$product->id}/barcodes"));
});

it('guards POST /products/barcodes with catalog.update', function (): void {
    $product = Product::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogUpdate, fn () => $this->postJson('/api/v1/admin/products/barcodes', ['product_ids' => [$product->id]]));
});

it('leaves barcodes untouched for a role without catalog.update', function (): void {
    $product = Product::factory()->create();

    RoleMatrix::assertFor($this, Role::Viewer, false, fn () => $this->postJson("/api/v1/admin/products/{$product->id}/barcodes"));

    expect(ProductVariant::query()->whereNotNull('barcode')->count())->toBe(0);
});

it('guards GET /products/labels/templates with catalog.view', function (): void {
    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->getJson('/api/v1/admin/products/labels/templates'));
});

it('guards POST /products/labels with catalog.view', function (): void {
    $variant = ProductVariant::factory()->create(['barcode' => '2000000000015']);

    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->postJson('/api/v1/admin/products/labels', ['template' => 'roll_38x25', 'items' => [['variant_id' => $variant->id, 'copies' => 1]]]));
});

it('guards generate_missing on POST /products/labels with catalog.view and catalog.update', function (): void {
    foreach (Role::cases() as $role) {
        $variant = ProductVariant::factory()->create(['barcode' => null]);
        $allowed = RoleMatrix::allows($role, Permission::CatalogView) && RoleMatrix::allows($role, Permission::CatalogUpdate);

        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/products/labels', ['template' => 'roll_38x25', 'generate_missing' => true, 'items' => [['variant_id' => $variant->id, 'copies' => 1]]]));

        expect($variant->refresh()->barcode !== null)->toBe($allowed);
    }
});

it('refuses a guest with 401 on every barcode and label endpoint', function (string $method, string $uri): void {
    $product = Product::factory()->create();

    $this->json($method, str_replace('{id}', (string) $product->id, $uri))->assertUnauthorized();
})->with([
    ['POST', '/api/v1/admin/products/{id}/barcodes'],
    ['POST', '/api/v1/admin/products/barcodes'],
    ['GET', '/api/v1/admin/products/labels/templates'],
    ['POST', '/api/v1/admin/products/labels'],
]);
