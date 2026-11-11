<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Jobs\DeleteMediaFiles;
use App\Domain\Catalog\Jobs\GenerateImageRenditions;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\ImagePipeline;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;

/*
 * Product images and the media pipeline (P4-CAT-06/07):
 * /api/v1/admin/products/{product}/images.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'media.cdn_url' => null]);
    Storage::fake('public');
    Storage::fake('local');

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
});

function uploadImage(Product $product, string $bytes, array $fields = [], string $name = 'photo.jpg', string $mime = 'image/jpeg')
{
    return test()->post("/api/v1/admin/products/{$product->id}/images", [
        'file' => ImageFixtures::upload($bytes, $name, $mime),
        ...$fields,
    ], ['Accept' => 'application/json']);
}

it('uploads, re-encodes and generates WebP + AVIF at every width the source reaches', function (): void {
    $product = Product::factory()->create();

    $response = uploadImage($product, ImageFixtures::bytes('jpeg', 1000, 1000), ['alt_text' => 'Brick Box', 'alt_text_bn' => 'ব্রিক বক্স']);

    $response->assertCreated()
        ->assertJsonPath('processing_status', 'ready') // the sync test queue ran the job
        ->assertJsonPath('width', 1000)
        ->assertJsonPath('height', 1000)
        ->assertJsonPath('is_primary', true)
        ->assertJsonPath('position', 0)
        ->assertJsonPath('alt_text_bn', 'ব্রিক বক্স');

    $image = ProductImage::query()->sole();
    expect($image->rendition_widths)->toBe([160, 320, 480, 800]); // 1200 would upscale

    foreach ([160, 320, 480, 800] as $width) {
        Storage::disk('public')->assertExists("{$image->path}/{$width}.webp");
        Storage::disk('public')->assertExists("{$image->path}/{$width}.avif");
        expect(ImagePipeline::magicType((string) Storage::disk('public')->get("{$image->path}/{$width}.avif")))->toBe('image/avif');
    }

    Storage::disk('public')->assertMissing("{$image->path}/1200.webp");
    // The master is private; the public disk holds renditions only.
    Storage::disk('local')->assertExists("{$image->path}/master.webp");
    Storage::disk('public')->assertMissing("{$image->path}/master.webp");

    expect($response->json('urls.thumb'))->toEndWith("{$image->path}/320.webp")
        ->and($response->json('urls.medium'))->toEndWith("{$image->path}/480.webp")
        ->and($response->json('urls.large'))->toEndWith("{$image->path}/800.webp") // largest available
        ->and($response->json('srcset.avif'))->toContain('/160.avif 160w, ')
        ->and($response->json('srcset.webp'))->toEndWith('/800.webp 800w');
});

it('strips EXIF (including GPS) and applies the orientation', function (): void {
    $product = Product::factory()->create();
    $source = ImageFixtures::jpegWithExifGps(400, 200);

    // The fixture really carries GPS + orientation.
    $exif = exif_read_data('data://image/jpeg;base64,'.base64_encode($source));
    expect($exif)->toHaveKey('GPSLatitude')->and($exif['Orientation'])->toBe(6);

    uploadImage($product, $source)->assertCreated()
        // Orientation 6 is a 90° rotation: 400×200 becomes 200×400.
        ->assertJsonPath('width', 200)
        ->assertJsonPath('height', 400);

    $image = ProductImage::query()->sole();
    $files = [
        Storage::disk('local')->get("{$image->path}/master.webp"),
        Storage::disk('public')->get("{$image->path}/160.webp"),
        Storage::disk('public')->get("{$image->path}/160.avif"),
    ];

    foreach ($files as $bytes) {
        expect($bytes)->not->toContain('Exif')->not->toContain('EXIF')->not->toContain('MM'."\x00\x2A");
    }
});

it('rejects files whose real type is not an accepted image', function (string $bytes, string $name, string $mime): void {
    $product = Product::factory()->create();

    uploadImage($product, $bytes, [], $name, $mime)
        ->assertStatus(422)
        ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, 'validation-error'))
        ->assertJsonPath('errors.0.field', 'file');

    expect(ProductImage::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'a PHP script renamed .jpg' => ["<?php system(\$_GET['c']); ?>", 'shell.jpg', 'image/jpeg'],
    'a PHP script as .php' => ['<?php echo 1; ?>', 'shell.php', 'application/x-php'],
    'an SVG' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg', 'image/svg+xml'],
    'an SVG claiming PNG' => ['<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>', 'logo.png', 'image/png'],
    'a GIF' => ["GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;", 'a.gif', 'image/gif'],
    'a JPEG/PHP polyglot' => [ImageFixtures::bytes('jpeg', 50, 50)."<?php system('id'); ?>", 'poly.jpg', 'image/jpeg'],
    'a truncated JPEG' => ["\xFF\xD8\xFF\xE0".str_repeat("\x00", 64), 'broken.jpg', 'image/jpeg'],
]);

it('accepts PNG, WebP and AVIF by content whatever the name says', function (string $format): void {
    $product = Product::factory()->create();

    uploadImage($product, ImageFixtures::bytes($format, 300, 300), [], 'no-extension', 'application/octet-stream')
        ->assertCreated()
        ->assertJsonPath('processing_status', 'ready');
})->with(['png', 'webp', 'avif']);

it('rejects an image over the size or dimension limits', function (): void {
    $product = Product::factory()->create();

    config(['media.max_dimension' => 500]);
    uploadImage($product, ImageFixtures::bytes('png', 600, 100))->assertStatus(422)->assertJsonPath('errors.0.field', 'file');

    config(['media.max_dimension' => 8000, 'media.max_bytes' => 100]);
    uploadImage($product, ImageFixtures::bytes('png', 300, 300))->assertStatus(422)->assertJsonPath('errors.0.field', 'file');
});

it('rejects an upload over 10 MB before it reaches the pipeline', function (): void {
    $product = Product::factory()->create();
    $file = Illuminate\Http\UploadedFile::fake()->create('big.jpg', 10241, 'image/jpeg');

    $this->post("/api/v1/admin/products/{$product->id}/images", ['file' => $file], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'file');
});

it('returns processing until the queued job runs, after commit, on the media queue', function (): void {
    Queue::fake();
    $product = Product::factory()->create();

    uploadImage($product, ImageFixtures::bytes())->assertCreated()
        ->assertJsonPath('processing_status', 'processing')
        ->assertJsonPath('urls.thumb', null)
        ->assertJsonPath('srcset.webp', null)
        ->assertJsonPath('width', 400);

    Queue::assertPushedOn('index', GenerateImageRenditions::class, fn (GenerateImageRenditions $job): bool => $job->afterCommit === true);
    expect(ProductImage::query()->sole()->url(480))->toBeNull();
});

it('crops on upload and re-crops an existing image to a new immutable path', function (): void {
    $product = Product::factory()->create();

    uploadImage($product, ImageFixtures::bytes('jpeg', 800, 400), ['crop' => ['x' => 100, 'y' => 0, 'width' => 400, 'height' => 400]])
        ->assertCreated()->assertJsonPath('width', 400)->assertJsonPath('height', 400);

    $image = ProductImage::query()->sole();
    $old = $image->path;

    $this->postJson("/api/v1/admin/products/{$product->id}/images/{$image->id}/crop", ['x' => 0, 'y' => 0, 'width' => 200, 'height' => 100])
        ->assertOk()->assertJsonPath('width', 200)->assertJsonPath('height', 100)->assertJsonPath('processing_status', 'ready');

    expect($image->refresh()->path)->not->toBe($old);
    Storage::disk('public')->assertMissing("{$old}/160.webp");
    Storage::disk('local')->assertMissing("{$old}/master.webp");
    Storage::disk('public')->assertExists("{$image->path}/160.webp");
});

it('lists images by position and appends uploads; only the first is primary', function (): void {
    $product = Product::factory()->create();
    uploadImage($product, ImageFixtures::bytes())->assertCreated();
    uploadImage($product, ImageFixtures::bytes('png'), [], 'b.png', 'image/png')->assertCreated()
        ->assertJsonPath('position', 1)->assertJsonPath('is_primary', false);

    $this->getJson("/api/v1/admin/products/{$product->id}/images")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.is_primary', true)
        ->assertJsonPath('data.1.position', 1);
});

it('updates alt text, variant and primary, and validates the variant belongs to the product', function (): void {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
    $first = ProductImage::factory()->create(['product_id' => $product->id, 'position' => 0, 'is_primary' => true]);
    $second = ProductImage::factory()->create(['product_id' => $product->id, 'position' => 1]);
    $foreign = ProductVariant::factory()->create();
    $url = "/api/v1/admin/products/{$product->id}/images/{$second->id}";

    $this->patchJson($url, ['alt_text' => '  Box front ', 'alt_text_bn' => 'বাক্সের সামনে', 'variant_id' => $variant->id, 'is_primary' => true])
        ->assertOk()
        ->assertJsonPath('alt_text', 'Box front')
        ->assertJsonPath('variant_id', $variant->id)
        ->assertJsonPath('is_primary', true);

    expect($first->refresh()->is_primary)->toBeFalse();

    $this->patchJson($url, ['variant_id' => $foreign->id])->assertStatus(422)->assertJsonPath('errors.0.field', 'variant_id');
    $this->patchJson($url, ['is_primary' => false])->assertStatus(422)->assertJsonPath('errors.0.field', 'is_primary');
    $this->patchJson($url, ['variant_id' => null])->assertOk()->assertJsonPath('variant_id', null);
});

it('does not reach an image through another product', function (): void {
    $product = Product::factory()->create();
    $other = ProductImage::factory()->create();

    $this->patchJson("/api/v1/admin/products/{$product->id}/images/{$other->id}", ['alt_text' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/admin/products/{$product->id}/images/{$other->id}")->assertNotFound();
});

it('reorders images and requires every id exactly once', function (): void {
    $product = Product::factory()->create();
    $a = ProductImage::factory()->create(['product_id' => $product->id, 'position' => 0]);
    $b = ProductImage::factory()->create(['product_id' => $product->id, 'position' => 1]);
    $c = ProductImage::factory()->create(['product_id' => $product->id, 'position' => 2]);
    $url = "/api/v1/admin/products/{$product->id}/images/reorder";

    $this->postJson($url, ['ids' => [$c->id, $a->id, $b->id]])
        ->assertOk()
        ->assertJsonPath('data.0.id', $c->id)
        ->assertJsonPath('data.2.id', $b->id);

    expect($c->refresh()->position)->toBe(0)->and($b->refresh()->position)->toBe(2);

    $this->postJson($url, ['ids' => [$a->id, $b->id]])->assertStatus(422)->assertJsonPath('errors.0.field', 'ids');
    $this->postJson($url, ['ids' => [$a->id, $b->id, $c->id, ProductImage::factory()->create()->id]])->assertStatus(422);
});

it('deletes an image, closes positions, promotes a new primary and removes its files after commit', function (): void {
    $product = Product::factory()->create();
    uploadImage($product, ImageFixtures::bytes())->assertCreated();
    uploadImage($product, ImageFixtures::bytes())->assertCreated();
    [$first, $second] = ProductImage::query()->orderBy('position')->get()->all();

    $this->deleteJson("/api/v1/admin/products/{$product->id}/images/{$first->id}")->assertNoContent();

    expect(ProductImage::query()->find($first->id))->toBeNull()
        ->and($second->refresh()->position)->toBe(0)
        ->and($second->is_primary)->toBeTrue();
    Storage::disk('public')->assertMissing("{$first->path}/160.webp");
    Storage::disk('local')->assertMissing("{$first->path}/master.webp");
    Storage::disk('public')->assertExists("{$second->path}/160.webp");
});

it('keeps files a duplicated product still shares', function (): void {
    $product = Product::factory()->create();
    uploadImage($product, ImageFixtures::bytes())->assertCreated();
    $image = ProductImage::query()->sole();
    $copy = ProductImage::factory()->create(['path' => $image->path, 'processing_status' => ImageProcessingStatus::Ready, 'rendition_widths' => [160, 320]]);

    $this->deleteJson("/api/v1/admin/products/{$product->id}/images/{$image->id}")->assertNoContent();

    Storage::disk('public')->assertExists("{$image->path}/160.webp");
    expect($copy->refresh()->url(160))->not->toBeNull();
});

it('queues the file deletion after commit', function (): void {
    Queue::fake();
    $image = ProductImage::factory()->create(['path' => 'products/1/abc']);

    $this->deleteJson("/api/v1/admin/products/{$image->product_id}/images/{$image->id}")->assertNoContent();

    Queue::assertPushedOn('index', DeleteMediaFiles::class, fn (DeleteMediaFiles $job): bool => $job->base === 'products/1/abc' && $job->afterCommit === true);
});

it('revalidates the product page when images change', function (): void {
    Queue::fake();
    $product = Product::factory()->create(['slug' => 'brick-box']);
    $image = ProductImage::factory()->create(['product_id' => $product->id, 'is_primary' => true]);

    $this->patchJson("/api/v1/admin/products/{$product->id}/images/{$image->id}", ['alt_text' => 'New alt'])->assertOk();
    $this->deleteJson("/api/v1/admin/products/{$product->id}/images/{$image->id}")->assertNoContent();

    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['products', 'product:brick-box']);
    Queue::assertPushed(RevalidateStorefrontTags::class, 2);
});

it('serves rendition URLs from MEDIA_CDN_URL when set', function (): void {
    config(['media.cdn_url' => 'https://media.toymartbd.test/']);
    $image = ProductImage::factory()->create(['path' => 'products/1/abc', 'rendition_widths' => [160, 320, 480, 800, 1200]]);

    expect($image->url(480))->toBe('https://media.toymartbd.test/products/1/abc/480.webp')
        ->and($image->url(481, 'avif'))->toBe('https://media.toymartbd.test/products/1/abc/800.avif')
        ->and($image->url(5000))->toBe('https://media.toymartbd.test/products/1/abc/1200.webp')
        ->and($image->url())->toBe('https://media.toymartbd.test/products/1/abc/1200.webp');
});

it('returns no URL for an image without a file, and composes alt text per locale', function (): void {
    $image = ProductImage::factory()->make(['path' => null, 'alt_text' => 'Brick Box', 'alt_text_bn' => null]);

    expect($image->url(480))->toBeNull()
        ->and($image->srcset())->toBeNull()
        ->and($image->alt('en'))->toBe('Brick Box')
        ->and($image->alt('bn'))->toBe('Brick Box')
        ->and($image->hasCompleteAlt())->toBeFalse()
        ->and(ProductImage::WIDTHS)->toBe(config('media.widths'));
});

it('refuses to publish a product while an image lacks English or Bangla alt text', function (): void {
    $data = Tests\Support\CatalogFixtures::simple(['status' => App\Domain\Catalog\Enums\ProductStatus::Draft]);
    $product = app(App\Domain\Catalog\Actions\CreateProduct::class)->handle($data);
    ProductImage::factory()->create(['product_id' => $product->id, 'position' => 0, 'alt_text' => 'Front', 'alt_text_bn' => 'সামনে']);
    $missing = ProductImage::factory()->create(['product_id' => $product->id, 'position' => 1, 'alt_text' => 'Back', 'alt_text_bn' => ' ']);

    $publish = fn () => app(App\Domain\Catalog\Actions\UpdateProduct::class)->handle(
        $product->refresh(),
        new App\Domain\Catalog\DTOs\ProductData(...[...get_object_vars($data), 'status' => App\Domain\Catalog\Enums\ProductStatus::Active]),
    );

    expect($publish)->toThrow(Illuminate\Validation\ValidationException::class, "image(s) 2 (id {$missing->id})");
    expect($product->refresh()->status)->toBe(App\Domain\Catalog\Enums\ProductStatus::Draft);

    // A draft save is fine with incomplete alt text.
    app(App\Domain\Catalog\Actions\UpdateProduct::class)->handle($product, $data);

    $missing->update(['alt_text_bn' => 'পেছনে']);
    $publish();
    expect($product->refresh()->status)->toBe(App\Domain\Catalog\Enums\ProductStatus::Active);
});

it('reports the alt-text publish rule on status over the API', function (): void {
    $product = Product::factory()->priced('500.00')->create();
    ProductImage::factory()->create(['product_id' => $product->id, 'alt_text' => null, 'alt_text_bn' => null]);

    $this->putJson("/api/v1/admin/products/{$product->id}", [
        'name' => $product->name,
        'type' => 'simple',
        'status' => 'active',
        'primary_category_id' => $product->primary_category_id,
        'category_ids' => [$product->primary_category_id],
        'variants' => [['price' => '500.00']],
    ])->assertStatus(422)->assertJsonPath('errors.0.field', 'status');
});

it('caps a product at media.max_per_product images', function (): void {
    config(['media.max_per_product' => 2]);
    $product = Product::factory()->create();
    ProductImage::factory()->count(2)->create(['product_id' => $product->id]);

    uploadImage($product, ImageFixtures::bytes())->assertStatus(422)->assertJsonPath('errors.0.field', 'file');
});
