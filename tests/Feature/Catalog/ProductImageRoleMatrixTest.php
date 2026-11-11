<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Models\Enums\Permission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for every product-image endpoint (CLAUDE.md rule 21): reads need
 * catalog.view, every write catalog.update.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake(); // renditions are not the point here
    Storage::fake('public');
    Storage::fake('local');
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

it('guards GET /products/{product}/images with catalog.view', function (): void {
    $product = Product::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->getJson("/api/v1/admin/products/{$product->id}/images"));
});

it('guards POST /products/{product}/images with catalog.update', function (): void {
    $product = Product::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogUpdate, fn () => $this->post(
        "/api/v1/admin/products/{$product->id}/images",
        ['file' => ImageFixtures::upload(ImageFixtures::bytes('png', 50, 50), 'a.png', 'image/png')],
        ['Accept' => 'application/json'],
    ), 201);
});

it('guards PATCH /products/{product}/images/{image} with catalog.update', function (): void {
    $image = ProductImage::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogUpdate, fn () => $this->patchJson("/api/v1/admin/products/{$image->product_id}/images/{$image->id}", ['alt_text' => 'Alt '.uniqid()]));
});

it('guards POST /products/{product}/images/reorder with catalog.update', function (): void {
    $image = ProductImage::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogUpdate, fn () => $this->postJson("/api/v1/admin/products/{$image->product_id}/images/reorder", ['ids' => [$image->id]]));
});

it('guards POST /products/{product}/images/{image}/crop with catalog.update', function (): void {
    $product = Product::factory()->create();
    Storage::disk('local')->put('products/1/x/master.webp', ImageFixtures::bytes('webp', 100, 100));
    $image = ProductImage::factory()->create(['product_id' => $product->id, 'path' => 'products/1/x']);

    RoleMatrix::assert($this, Permission::CatalogUpdate, function () use ($image) {
        // Each allowed crop moves the master; put it back so the next role has one.
        $path = $image->refresh()->path;
        if (! Storage::disk('local')->exists("{$path}/master.webp")) {
            Storage::disk('local')->put("{$path}/master.webp", ImageFixtures::bytes('webp', 100, 100));
        }

        return $this->postJson("/api/v1/admin/products/{$image->product_id}/images/{$image->id}/crop", ['x' => 0, 'y' => 0, 'width' => 50, 'height' => 50]);
    });
});

it('guards DELETE /products/{product}/images/{image} with catalog.update', function (): void {
    $product = Product::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogUpdate, function () use ($product) {
        $image = ProductImage::factory()->create(['product_id' => $product->id]);

        return $this->deleteJson("/api/v1/admin/products/{$product->id}/images/{$image->id}");
    }, 204);
});
