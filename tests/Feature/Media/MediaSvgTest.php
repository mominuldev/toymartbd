<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Jobs\GenerateMediaRenditions;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\VectorImage;
use App\Domain\Invoicing\Services\StoreBranding;
use App\Http\Controllers\Web\PublicMediaController;
use App\Http\Rules\NotSvgUrl;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\Feature\Media\MediaKit;
use Tests\Support\ImageFixtures;

/*
 * SVG in the media library (owner decision 2026-11-28): kept as a sanitised vector, stored
 * privately, served only through /m/ with a sandbox CSP, and refused wherever pixels are
 * needed. Raster uploads still become a WebP master.
 */

const SVG_LOGO = <<<'SVG'
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 240 120" onload="alert(1)">
  <script>alert(document.cookie)</script>
  <a xlink:href="javascript:alert(2)"><rect width="240" height="120" fill="#ff7a00"/></a>
  <image xlink:href="https://evil.example/track.png" width="10" height="10"/>
  <foreignObject width="10" height="10"><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>
  <text x="20" y="70">খেলনা</text>
</svg>
SVG;

const SVG_PLAIN = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="32"><circle cx="16" cy="16" r="12" fill="#2a6"/></svg>';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'media.cdn_url' => null]);
    Storage::fake('public');
    Storage::fake('local');

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh(), 'web');
});

function uploadSvg(string $svg = SVG_LOGO, string $name = 'Smart Junior logo.svg', string $locale = 'en'): Illuminate\Testing\TestResponse
{
    return MediaKit::upload(ImageFixtures::upload($svg, $name, 'image/svg+xml'), [], $locale);
}

/** @return list<string> the messages on one field of an RFC 9457 validation error */
function fieldErrors(Illuminate\Testing\TestResponse $response, string $field): array
{
    return array_values(array_map(
        static fn (array $e): string => (string) $e['message'],
        array_filter((array) $response->json('errors'), static fn (mixed $e): bool => is_array($e) && ($e['field'] ?? null) === $field),
    ));
}

it('stores an uploaded SVG as a sanitised vector: ready at once, private, no renditions, no job', function (): void {
    Queue::fake();

    $response = uploadSvg()->assertCreated()
        ->assertJsonPath('kind', 'image')
        ->assertJsonPath('mime', 'image/svg+xml')
        ->assertJsonPath('original_mime', 'image/svg+xml')
        ->assertJsonPath('processing_status', 'ready')
        ->assertJsonPath('filename', 'Smart-Junior-logo.svg')
        ->assertJsonPath('width', 240)
        ->assertJsonPath('height', 120)
        ->assertJsonPath('srcset.webp', null)
        ->assertJsonPath('srcset.avif', null);

    Queue::assertNotPushed(GenerateMediaRenditions::class);

    $media = Media::query()->findOrFail($response->json('id'));
    $stored = (string) Storage::disk('local')->get("{$media->base_path}/image.svg");

    expect($stored)->toContain('<rect')->toContain('খেলনা')
        ->not->toContain('<script')->not->toContain('onload')->not->toContain('javascript:')
        ->not->toContain('evil.example')->not->toContain('foreignObject')
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and($media->size_bytes)->toBe(strlen($stored))
        ->and($response->json('urls.thumb'))->toBe(url("/m/{$media->id}/smart-junior-logo.svg").'?v='.$media->version())
        ->and($response->json('public_url'))->toBe(url("/m/{$media->id}/smart-junior-logo.svg"));
});

it('reads the size from width/height when the root gives them', function (): void {
    uploadSvg(SVG_PLAIN, 'dot.svg')->assertCreated()->assertJsonPath('width', 64)->assertJsonPath('height', 32);
});

it('refuses an SVG declaring a DOCTYPE or entities (XXE, entity expansion), in English and Bangla', function (): void {
    $xxe = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>';

    uploadSvg($xxe)->assertStatus(422)->assertJsonPath('errors.0.field', 'file')
        ->assertJsonPath('errors.0.message', 'The file contains embedded script or markup and was refused.');
    uploadSvg($xxe, 'x.svg', 'bn')->assertStatus(422)
        ->assertJsonPath('errors.0.message', 'ফাইলের ভেতরে স্ক্রিপ্ট বা মার্কআপ আছে, তাই নেওয়া হয়নি।');

    expect(Media::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('still refuses an SVG hidden inside a raster file', function (): void {
    MediaKit::upload(ImageFixtures::upload(ImageFixtures::bytes('png', 40, 40).'<svg onload="alert(1)"/>', 'poly.png', 'image/png'))
        ->assertStatus(422)->assertJsonPath('errors.0.message', 'The file contains embedded script or markup and was refused.');
});

it('still converts a raster upload to a WebP master', function (string $format): void {
    MediaKit::upload(MediaKit::image("toy.{$format}", 300, 300, $format))->assertCreated()
        ->assertJsonPath('mime', 'image/webp')
        ->assertJsonPath('original_mime', "image/{$format}");
})->with(['jpeg', 'png', 'webp', 'avif']);

it('serves the SVG from /m/ with a sandbox CSP and nosniff; immutable only for the current version', function (): void {
    $media = Media::query()->findOrFail(uploadSvg()->json('id'));

    $current = $this->get('/m/'.$media->id.'/smart-junior-logo.svg?v='.$media->version())->assertOk();
    expect($current->headers->get('Content-Type'))->toBe('image/svg+xml')
        ->and($current->headers->get('Content-Security-Policy'))->toBe(PublicMediaController::SVG_CSP)
        ->and($current->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($current->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($current->headers->get('Cache-Control'))->toContain('immutable')
        ->and($current->getContent())->not->toContain('<script');

    $stale = $this->get('/m/'.$media->id.'/anything.svg?v=old')->assertOk();
    expect($stale->headers->get('Cache-Control'))->toContain('max-age=300')->not->toContain('immutable');
});

it('replaces an SVG only with an SVG, and a raster image only with a raster image', function (): void {
    $svg = Media::query()->findOrFail(uploadSvg()->json('id'));
    $raster = Media::query()->findOrFail(MediaKit::upload(MediaKit::image())->json('id'));
    $oldBase = $svg->base_path;

    $this->post("/api/v1/admin/media/{$svg->id}/replace", ['file' => MediaKit::image()], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('errors.0.message', 'Replace with an SVG: this file is an SVG.');
    $this->post("/api/v1/admin/media/{$raster->id}/replace", ['file' => ImageFixtures::upload(SVG_PLAIN, 'dot.svg', 'image/svg+xml')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('errors.0.message', 'Replace with a JPG, PNG, WebP or AVIF image: this file is not an SVG.');

    $this->post("/api/v1/admin/media/{$svg->id}/replace", ['file' => ImageFixtures::upload(SVG_PLAIN, 'dot.svg', 'image/svg+xml')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('mime', 'image/svg+xml')->assertJsonPath('processing_status', 'ready')->assertJsonPath('width', 64);

    expect($svg->refresh()->base_path)->not->toBe($oldBase);
});

it('refuses an SVG in the product gallery', function (): void {
    $media = Media::query()->findOrFail(uploadSvg()->json('id'));
    $product = Product::factory()->create();

    $this->postJson("/api/v1/admin/products/{$product->id}/images", ['media_id' => $media->id])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'media_id')
        ->assertJsonPath('errors.0.message', 'An SVG cannot be a product photo. Use a JPG, PNG, WebP or AVIF image.');

    // And a direct upload to the gallery is still raster-only.
    $this->post("/api/v1/admin/products/{$product->id}/images", ['file' => ImageFixtures::upload(SVG_PLAIN, 'dot.svg', 'image/svg+xml')], ['Accept' => 'application/json'])
        ->assertStatus(422);
});

it('refuses an SVG as a profile photo', function (): void {
    $media = Media::query()->findOrFail(uploadSvg()->json('id'));

    $this->postJson('/api/v1/admin/auth/avatar', ['media_id' => $media->id])
        ->assertStatus(422)->assertJsonPath('errors.0.message', 'An SVG cannot be a profile photo. Pick a JPG, PNG, WebP or AVIF image.');
});

it('accepts an AVIF profile photo', function (): void {
    $this->post('/api/v1/admin/auth/avatar', ['avatar' => ImageFixtures::upload(ImageFixtures::bytes('avif', 300, 300), 'me.avif', 'image/avif')], ['Accept' => 'application/json'])
        ->assertOk();
});

it('refuses an SVG for branding slots and the invoice logo', function (): void {
    $media = Media::query()->findOrFail(uploadSvg()->json('id'));

    expect(StoreBranding::mediaProblem('logo_media_id', $media))->toBe('store_settings.branding_media_svg')
        ->and(StoreBranding::readyImage($media->id))->toBeNull();

    $response = $this->putJson('/api/v1/admin/settings/invoice', ['logo_media_id' => $media->id])->assertStatus(422);
    expect(fieldErrors($response, 'logo_media_id'))->not->toBeEmpty();
});

it('refuses an SVG blog cover, which doubles as the share image', function (): void {
    $media = Media::query()->findOrFail(uploadSvg()->json('id'));

    $response = $this->postJson('/api/v1/admin/blog/posts', ['cover_media_id' => $media->id])->assertStatus(422);

    expect(fieldErrors($response, 'cover_media_id'))
        ->toContain('A cover is also the social share image, which cannot be an SVG. Choose a JPG, PNG, WebP or AVIF image.');
});

it('refuses an .svg URL as a social share image', function (string $url, bool $passes): void {
    expect(Validator::make(['og' => $url], ['og' => [new NotSvgUrl]])->passes())->toBe($passes);
})->with([
    'library SVG' => ['https://api.toymartbd.com/m/12/logo.svg?v=01hx', false],
    'upper case' => ['https://cdn.example.com/a/LOGO.SVG', false],
    'webp' => ['https://api.toymartbd.com/m/12/logo.webp', true],
    'svg in the query only' => ['https://cdn.example.com/a.png?x=.svg', true],
]);

it('reads the size of an SVG from the viewBox, keeping the ratio when one side is given', function (string $svg, ?int $w, ?int $h): void {
    expect(VectorImage::dimensions($svg))->toBe([$w, $h]);
})->with([
    'viewBox only' => ['<svg viewBox="0 0 300 150">', 300, 150],
    'width only' => ['<svg width="600" viewBox="0 0 300 150">', 600, 300],
    'percent width' => ['<svg width="100%" height="100%" viewBox="0,0,20,10">', 20, 10],
    'nothing' => ['<svg>', null, null],
]);

it('keeps an SVG out of the processing state even though it is an image', function (): void {
    $media = Media::query()->findOrFail(uploadSvg()->json('id'));

    expect($media->isVector())->toBeTrue()
        ->and($media->isRaster())->toBeFalse()
        ->and($media->processing_status)->toBe(ImageProcessingStatus::Ready)
        ->and($media->originalPath())->toEndWith('/image.svg')
        ->and($media->downloadName())->toEndWith('.svg');
});
