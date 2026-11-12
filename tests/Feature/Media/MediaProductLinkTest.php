<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Media\Enums\MediaOrigin;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Models\MediaFolder;
use App\Domain\Catalog\Media\Services\ProductImageMediaBackfill;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Media\MediaKit;
use Tests\Support\ImageFixtures;

/*
 * The library and product images: one source of truth for the file and the alt text,
 * attach from the library, product uploads as library rows, and the backfill.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'media.cdn_url' => null]);
    Storage::fake('public');
    Storage::fake('local');

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
});

function attach(Product $product, int $mediaId, array $fields = [])
{
    return test()->postJson("/api/v1/admin/products/{$product->id}/images", ['media_id' => $mediaId, ...$fields]);
}

it('files a product-form upload in the library: Products folder, origin product, linked', function (): void {
    $product = Product::factory()->create();

    test()->post("/api/v1/admin/products/{$product->id}/images", [
        'file' => ImageFixtures::upload(ImageFixtures::bytes('png', 500, 500), 'Brick Box front.png', 'image/png'),
        'alt_text' => 'Brick Box', 'alt_text_bn' => 'ব্রিক বক্স',
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('alt_text_bn', 'ব্রিক বক্স');

    $media = Media::query()->sole();
    $image = ProductImage::query()->sole();

    expect($image->media_id)->toBe($media->id)
        ->and($media->origin)->toBe(MediaOrigin::Product)
        ->and($media->folder_id)->toBe(MediaFolder::systemId(MediaFolder::PRODUCTS))
        ->and($media->filename)->toBe('Brick-Box-front.png')
        ->and($media->original_mime)->toBe('image/png')
        ->and($media->processing_status)->toBe(ImageProcessingStatus::Ready)
        ->and($media->alt_text_bn)->toBe('ব্রিক বক্স')
        ->and($image->path)->toBe($media->base_path);

    $this->getJson("/api/v1/admin/media/{$media->id}")->assertJsonPath('used_in.0.subject_id', $product->id);
});

it('attaches a library image to a product: shares the base, appended, primary when first', function (): void {
    MediaKit::upload(MediaKit::image('cube.jpg', 1200, 1200), ['alt_text' => 'Speed cube', 'alt_text_bn' => 'স্পিড কিউব'])->assertCreated();
    $media = Media::query()->sole();
    $product = Product::factory()->create();

    attach($product, $media->id)->assertCreated()
        ->assertJsonPath('is_primary', true)
        ->assertJsonPath('position', 0)
        ->assertJsonPath('processing_status', 'ready')
        ->assertJsonPath('width', 1200)
        ->assertJsonPath('alt_text_bn', 'স্পিড কিউব')
        ->assertJsonPath('urls.large', fn (string $url): bool => str_ends_with($url, "{$media->base_path}/1200.webp"));

    $other = Media::factory()->create();
    attach($product, $other->id)->assertCreated()->assertJsonPath('position', 1)->assertJsonPath('is_primary', false);

    expect(Media::query()->count())->toBe(2) // nothing copied
        ->and(ProductImage::query()->where('product_id', $product->id)->pluck('media_id')->all())->toBe([$media->id, $other->id]);
});

it('refuses to attach a PDF, the same image twice, both file and media_id, a crop, or past the cap', function (): void {
    $product = Product::factory()->create();
    $pdf = Media::factory()->document()->create();
    $image = Media::factory()->create();

    attach($product, $pdf->id)->assertStatus(422)->assertJsonPath('errors.0.field', 'media_id')
        ->assertJsonPath('errors.0.message', 'Only an image can be added to the gallery.');
    attach($product, $image->id)->assertCreated();
    attach($product, $image->id)->assertStatus(422)->assertJsonPath('errors.0.message', 'This image is already in the product gallery.');
    attach($product, 999999)->assertStatus(422)->assertJsonPath('errors.0.field', 'media_id');

    $this->post("/api/v1/admin/products/{$product->id}/images", ['media_id' => $image->id, 'file' => MediaKit::image()], ['Accept' => 'application/json'])
        ->assertStatus(422);
    attach($product, Media::factory()->create()->id, ['crop' => ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10]])->assertStatus(422);
    $this->postJson("/api/v1/admin/products/{$product->id}/images", [])->assertStatus(422)->assertJsonPath('errors.0.field', 'file');

    config(['media.max_per_product' => 1]);
    attach($product, Media::factory()->create()->id)->assertStatus(422)->assertJsonPath('errors.0.field', 'media_id');
});

it('shares alt text: a product edit shows in the library and on every other product, and back', function (): void {
    $media = Media::factory()->create(['alt_text' => 'Old', 'alt_text_bn' => 'পুরনো']);
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    $imageA = ProductImage::query()->findOrFail(attach($a, $media->id)->assertCreated()->json('id'));
    attach($b, $media->id)->assertCreated();

    // Product → library and the other product.
    $this->patchJson("/api/v1/admin/products/{$a->id}/images/{$imageA->id}", ['alt_text_bn' => 'নতুন বাংলা'])
        ->assertOk()->assertJsonPath('alt_text_bn', 'নতুন বাংলা')->assertJsonPath('alt_text', 'Old');

    expect($media->refresh()->alt_text_bn)->toBe('নতুন বাংলা');
    $this->getJson("/api/v1/admin/products/{$b->id}/images")->assertJsonPath('data.0.alt_text_bn', 'নতুন বাংলা');

    // Library → both products.
    $this->patchJson("/api/v1/admin/media/{$media->id}", ['alt_text' => 'Wooden train, side'])->assertOk();

    $this->getJson("/api/v1/admin/products/{$a->id}/images")->assertJsonPath('data.0.alt_text', 'Wooden train, side');
    $this->getJson("/api/v1/admin/products/{$b->id}/images")->assertJsonPath('data.0.alt_text', 'Wooden train, side');

    // The legacy columns are a mirror, never read for a linked row, but kept equal for a safe rollback.
    expect(ProductImage::query()->toBase()->where('product_id', $b->id)->value('alt_text'))->toBe('Wooden train, side');
});

it('writes alt text sent with an attach to the media', function (): void {
    $media = Media::factory()->create(['alt_text_bn' => null]);
    attach(Product::factory()->create(), $media->id, ['alt_text_bn' => 'রঙিন ব্লক'])->assertCreated()->assertJsonPath('alt_text_bn', 'রঙিন ব্লক');

    expect($media->refresh()->alt_text_bn)->toBe('রঙিন ব্লক');
});

it('revalidates every product that shows the file when its alt text changes in the library', function (): void {
    $media = Media::factory()->create();
    ProductImage::factory()->create(['product_id' => Product::factory()->create(['slug' => 'brick-box'])->id, 'media_id' => $media->id]);
    ProductImage::factory()->create(['product_id' => Product::factory()->create(['slug' => 'brick-box-copy'])->id, 'media_id' => $media->id]);
    Queue::fake();

    $this->patchJson("/api/v1/admin/media/{$media->id}", ['alt_text' => 'New'])->assertOk();

    Queue::assertPushed(RevalidateStorefrontTags::class, fn ($job): bool => $job->tags === ['products', 'product:brick-box']);
    Queue::assertPushed(RevalidateStorefrontTags::class, fn ($job): bool => $job->tags === ['products', 'product:brick-box-copy']);
});

it('keeps the publish rule working through the media alt text', function (): void {
    $data = Tests\Support\CatalogFixtures::simple(['status' => ProductStatus::Draft]);
    $product = app(App\Domain\Catalog\Actions\CreateProduct::class)->handle($data);
    $media = Media::factory()->create(['alt_text' => 'Front', 'alt_text_bn' => null]);
    attach($product, $media->id)->assertCreated();

    $publish = fn () => app(App\Domain\Catalog\Actions\UpdateProduct::class)->handle(
        $product->refresh(),
        new App\Domain\Catalog\DTOs\ProductData(...[...get_object_vars($data), 'status' => ProductStatus::Active]),
    );

    expect($publish)->toThrow(Illuminate\Validation\ValidationException::class);

    $this->patchJson("/api/v1/admin/media/{$media->id}", ['alt_text_bn' => 'সামনে'])->assertOk();
    $publish();
    expect($product->refresh()->status)->toBe(ProductStatus::Active);
});

it('deletes a product-upload file with its last usage, but keeps a library file as unused', function (): void {
    $product = Product::factory()->create();
    test()->post("/api/v1/admin/products/{$product->id}/images", ['file' => MediaKit::image()], ['Accept' => 'application/json'])->assertCreated();
    $uploaded = ProductImage::query()->sole();
    $productMedia = $uploaded->media;

    $library = Media::factory()->create();
    $attached = attach($product, $library->id)->assertCreated()->json('id');

    $this->deleteJson("/api/v1/admin/products/{$product->id}/images/{$uploaded->id}")->assertNoContent();
    $this->deleteJson("/api/v1/admin/products/{$product->id}/images/{$attached}")->assertNoContent();

    expect(Media::query()->find($productMedia?->id))->toBeNull()
        ->and(Media::query()->find($library->id))->not->toBeNull();
    Storage::disk('local')->assertMissing("{$productMedia?->base_path}/master.webp");

    $this->getJson('/api/v1/admin/media?filter[unused]=1')->assertJsonPath('data.0.id', $library->id);
});

it('keeps a product-upload file while another product still uses it', function (): void {
    $product = Product::factory()->create();
    test()->post("/api/v1/admin/products/{$product->id}/images", ['file' => MediaKit::image()], ['Accept' => 'application/json'])->assertCreated();
    $image = ProductImage::query()->sole();
    attach(Product::factory()->create(), (int) $image->media_id)->assertCreated();

    $this->deleteJson("/api/v1/admin/products/{$product->id}/images/{$image->id}")->assertNoContent();

    expect(Media::query()->find($image->media_id))->not->toBeNull();
    Storage::disk('public')->assertExists("{$image->path}/160.webp");
});

it('shares one media row between a product and its duplicate', function (): void {
    $product = Product::factory()->create();
    test()->post("/api/v1/admin/products/{$product->id}/images", ['file' => MediaKit::image()], ['Accept' => 'application/json'])->assertCreated();

    $copy = app(App\Domain\Catalog\Actions\DuplicateProduct::class)->handle($product);

    expect(Media::query()->count())->toBe(1)
        ->and($copy->images()->sole()->media_id)->toBe(ProductImage::query()->where('product_id', $product->id)->sole()->media_id);
    $this->getJson('/api/v1/admin/media')->assertJsonPath('data.0.used_in_count', 2);
});

it('crops a shared library image into a new media row for that product only', function (): void {
    MediaKit::upload(MediaKit::image('shared.jpg', 600, 400))->assertCreated();
    $media = Media::query()->sole();
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    $imageA = attach($a, $media->id)->assertCreated()->json('id');
    attach($b, $media->id)->assertCreated();

    $this->postJson("/api/v1/admin/products/{$a->id}/images/{$imageA}/crop", ['x' => 0, 'y' => 0, 'width' => 300, 'height' => 300])
        ->assertOk()->assertJsonPath('width', 300)->assertJsonPath('processing_status', 'ready');

    $forked = ProductImage::query()->findOrFail($imageA);
    expect($forked->media_id)->not->toBe($media->id)
        ->and($media->refresh()->width)->toBe(600) // B is untouched
        ->and(Media::query()->count())->toBe(2);
    Storage::disk('public')->assertExists("{$media->base_path}/160.webp");
});

it('crops an image used only once in place, keeping the media id', function (): void {
    $product = Product::factory()->create();
    test()->post("/api/v1/admin/products/{$product->id}/images", ['file' => MediaKit::image('a.jpg', 600, 400)], ['Accept' => 'application/json'])->assertCreated();
    $image = ProductImage::query()->sole();
    $mediaId = $image->media_id;

    $this->postJson("/api/v1/admin/products/{$product->id}/images/{$image->id}/crop", ['x' => 0, 'y' => 0, 'width' => 200, 'height' => 200])->assertOk();

    expect($image->refresh()->media_id)->toBe($mediaId)
        ->and(Media::query()->count())->toBe(1)
        ->and(Media::query()->sole()->width)->toBe(200);
});

it('backfills a media row per distinct file, shares it between duplicates, and is idempotent', function (): void {
    $a = Product::factory()->create(['slug' => 'brick-box']);
    $b = Product::factory()->create();
    Storage::disk('local')->put('products/1/abc/master.webp', ImageFixtures::bytes('webp', 100, 100));
    Storage::disk('public')->put('products/1/abc/160.webp', str_repeat('x', 1234));
    Storage::disk('public')->put('products/1/abc/160.avif', str_repeat('y', 1000));

    $source = ProductImage::factory()->create(['product_id' => $a->id, 'path' => 'products/1/abc', 'rendition_widths' => [160], 'alt_text' => 'Brick Box', 'alt_text_bn' => null, 'position' => 1]);
    $dup = ProductImage::factory()->create(['product_id' => $b->id, 'path' => 'products/1/abc', 'rendition_widths' => [160], 'alt_text' => 'Other', 'alt_text_bn' => 'ব্রিক বক্স']);
    $own = ProductImage::factory()->create(['product_id' => $b->id, 'path' => 'products/2/def', 'rendition_widths' => [160]]);
    $noFile = ProductImage::factory()->create(['product_id' => $b->id, 'path' => null]);

    $first = app(ProductImageMediaBackfill::class)->run();

    expect($first)->toBe(['linked' => 3, 'created' => 2, 'measured' => 1])
        ->and($source->refresh()->media_id)->toBe($dup->refresh()->media_id)
        ->and($own->refresh()->media_id)->not->toBe($source->media_id)
        ->and($noFile->refresh()->media_id)->toBeNull();

    $shared = Media::query()->findOrFail($source->media_id);
    expect($shared->origin)->toBe(MediaOrigin::Product)
        ->and($shared->folder_id)->toBe(MediaFolder::systemId(MediaFolder::PRODUCTS))
        ->and($shared->filename)->toBe("product-{$a->id}-photo-02.webp") // never the slug: it may carry a brand
        ->and($shared->alt_text)->toBe('Brick Box')        // the first row wins
        ->and($shared->alt_text_bn)->toBe('ব্রিক বক্স')     // a later row fills the gap
        ->and($shared->renditions_bytes)->toBe(2234)
        ->and($shared->size_bytes)->toBe(1234)
        ->and($shared->master_bytes)->toBeGreaterThan(0)
        ->and($source->url(160))->toEndWith('products/1/abc/160.webp');

    // Idempotent: nothing more to do, nothing duplicated.
    expect(app(ProductImageMediaBackfill::class)->run())->toBe(['linked' => 0, 'created' => 0, 'measured' => 0])
        ->and(Media::query()->count())->toBe(2);

    $this->artisan('toymart:media:backfill')->expectsOutputToContain('Linked 0 product image(s); created 0 media row(s)')->assertSuccessful();
});

it('reads a linked image from its media even when the mirror is stale', function (): void {
    $media = Media::factory()->create(['alt_text' => 'From media', 'width' => 640]);
    $image = ProductImage::factory()->create(['media_id' => $media->id, 'alt_text' => 'stale', 'width' => 1, 'path' => 'old/base']);

    expect($image->refresh()->alt_text)->toBe('From media')
        ->and($image->width)->toBe(640)
        ->and($image->path)->toBe($media->base_path);
});
