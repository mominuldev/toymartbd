<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Jobs\GenerateMediaRenditions;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Models\MediaFolder;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Services\ImagePipeline;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Media\MediaKit;
use Tests\Support\ImageFixtures;

/*
 * The media library API (docs/api/media-library.md): /api/v1/admin/media….
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'media.cdn_url' => null]);
    Storage::fake('public');
    Storage::fake('local');

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->withRole(Role::Manager)->create(['name' => 'নুসরাত জাহান'])->refresh();
    $this->actingAs($this->user, 'web');
    $this->products = MediaFolder::systemId(MediaFolder::PRODUCTS);
});

function productImageFor(Media $media, ?Product $product = null, int $position = 0): ProductImage
{
    return ProductImage::factory()->create([
        'product_id' => ($product ?? Product::factory()->create())->id,
        'media_id' => $media->id,
        'path' => $media->base_path,
        'position' => $position,
        'is_primary' => $position === 0,
    ]);
}

// ── Upload ──────────────────────────────────────────────────────────────────

it('uploads an image: sniffed, master stored privately, renditions after commit, Bangla alt kept', function (): void {
    $response = MediaKit::upload(MediaKit::image('Wooden Puzzle — front.JPG', 1000, 1000), [
        'folder_id' => $this->products,
        'alt_text' => 'Wooden alphabet puzzle, front',
        'alt_text_bn' => 'কাঠের বর্ণমালা পাজল, সামনে',
        'caption' => 'বাংলা ও ইংরেজি অক্ষর',
    ]);

    $response->assertCreated()
        ->assertJsonPath('kind', 'image')
        ->assertJsonPath('filename', 'Wooden-Puzzle-front.jpg')
        ->assertJsonPath('folder_id', $this->products)
        ->assertJsonPath('processing_status', 'ready') // the sync test queue ran the job
        ->assertJsonPath('mime', 'image/webp')
        ->assertJsonPath('original_mime', 'image/jpeg')
        ->assertJsonPath('width', 1000)
        ->assertJsonPath('alt_status', 'complete')
        ->assertJsonPath('alt_text_bn', 'কাঠের বর্ণমালা পাজল, সামনে')
        ->assertJsonPath('caption', 'বাংলা ও ইংরেজি অক্ষর')
        ->assertJsonPath('used_in_count', 0)
        ->assertJsonPath('origin', 'library')
        ->assertJsonPath('uploaded_by.name', 'নুসরাত জাহান');

    $media = Media::query()->sole();
    Storage::disk('local')->assertExists("{$media->base_path}/master.webp");
    Storage::disk('public')->assertMissing("{$media->base_path}/master.webp");
    Storage::disk('public')->assertExists("{$media->base_path}/800.avif");

    expect($media->renditionWidths())->toBe([160, 320, 480, 800])
        ->and($media->renditions_bytes)->toBeGreaterThan(0)
        ->and($media->size_bytes)->toBe(strlen((string) Storage::disk('public')->get("{$media->base_path}/800.webp")))
        ->and($response->json('public_url'))->toEndWith("/m/{$media->id}/wooden-puzzle-front.webp")
        ->and($response->json('urls.large'))->toEndWith("{$media->base_path}/800.webp")
        ->and($response->json())->not->toHaveKey('base_path');
});

it('queues renditions after commit on the media queue and returns processing', function (): void {
    Queue::fake();

    MediaKit::upload(MediaKit::image())->assertCreated()
        ->assertJsonPath('processing_status', 'processing')
        ->assertJsonPath('urls.thumb', null)
        ->assertJsonPath('public_url', null);

    Queue::assertPushedOn('index', GenerateMediaRenditions::class, fn (GenerateMediaRenditions $job): bool => $job->afterCommit === true);
});

it('uploads a PDF privately, ready at once, with no public URL and no alt needed', function (): void {
    MediaKit::upload(MediaKit::pdf('BSTI certificate 2026.pdf'), ['alt_text' => 'ignored'])
        ->assertCreated()
        ->assertJsonPath('kind', 'document')
        ->assertJsonPath('filename', 'BSTI-certificate-2026.pdf')
        ->assertJsonPath('processing_status', 'ready')
        ->assertJsonPath('alt_status', 'not_applicable')
        ->assertJsonPath('alt_text', null)
        ->assertJsonPath('urls.large', null)
        ->assertJsonPath('public_url', null)
        ->assertJsonPath('size_bytes', strlen(MediaKit::PDF));

    $media = Media::query()->sole();
    Storage::disk('local')->assertExists("{$media->base_path}/document.pdf");
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('refuses files by content with the cause, in English and Bangla, and stores nothing', function (string $bytes, string $name, string $message, string $bn): void {
    MediaKit::upload(ImageFixtures::upload($bytes, $name, 'image/jpeg'))
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/validation-error')
        ->assertJsonPath('errors.0.field', 'file')
        ->assertJsonPath('errors.0.message', $message);

    MediaKit::upload(ImageFixtures::upload($bytes, $name, 'image/jpeg'), [], 'bn')
        ->assertStatus(422)->assertJsonPath('errors.0.message', $bn);

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'a PHP script named .jpg' => ['<?php system($_GET["c"]); ?>', 'shell.jpg', 'Not a real image or PDF — the content is not JPG, PNG, WebP, AVIF or PDF.', 'আসল ছবি বা PDF নয় — ফাইলের ভেতরের ধরন JPG, PNG, WebP, AVIF বা PDF নয়।'],
    'an SVG' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.png', 'Not a real image or PDF — the content is not JPG, PNG, WebP, AVIF or PDF.', 'আসল ছবি বা PDF নয় — ফাইলের ভেতরের ধরন JPG, PNG, WebP, AVIF বা PDF নয়।'],
    'an MP4 video' => ["\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64), 'clip.mp4', 'Not a real image or PDF — the content is not JPG, PNG, WebP, AVIF or PDF.', 'আসল ছবি বা PDF নয় — ফাইলের ভেতরের ধরন JPG, PNG, WebP, AVIF বা PDF নয়।'],
    'a JPEG/PHP polyglot' => [ImageFixtures::bytes('jpeg', 50, 50)."<?php system('id'); ?>", 'poly.jpg', 'The file contains embedded script or markup and was refused.', 'ফাইলের ভেতরে স্ক্রিপ্ট বা মার্কআপ আছে, তাই নেওয়া হয়নি।'],
    'a PDF with JavaScript' => ["%PDF-1.4\n1 0 obj << /Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj\n%%EOF", 'evil.pdf', 'The PDF contains scripts, attachments or interactive content. Export it again as a plain PDF.', 'PDF-এ স্ক্রিপ্ট, সংযুক্তি বা ইন্টারঅ্যাকটিভ অংশ আছে। সাধারণ PDF হিসেবে আবার এক্সপোর্ট করুন।'],
    'a PDF with an embedded file' => ["%PDF-1.4\n1 0 obj << /Type /EmbeddedFile >> stream\nMZ\nendstream endobj\n%%EOF", 'attach.pdf', 'The PDF contains scripts, attachments or interactive content. Export it again as a plain PDF.', 'PDF-এ স্ক্রিপ্ট, সংযুক্তি বা ইন্টারঅ্যাকটিভ অংশ আছে। সাধারণ PDF হিসেবে আবার এক্সপোর্ট করুন।'],
]);

it('refuses over 10 MB and over 8000 px with the numbers', function (): void {
    $big = Illuminate\Http\UploadedFile::fake()->create('huge.jpg', 11674, 'image/jpeg'); // 11.4 MB

    MediaKit::upload($big)->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'file')
        ->assertJsonPath('errors.0.message', '11.4 MB — over the 10 MB limit.');

    config(['media.max_dimension' => 500]);
    MediaKit::upload(MediaKit::image('wide.png', 900, 600, 'png'))->assertStatus(422)
        ->assertJsonPath('errors.0.message', '900 × 600 px — over the 500 px limit per side.');
});

it('has no minimum size in the library', function (): void {
    MediaKit::upload(MediaKit::image('tiny.png', 40, 30, 'png'))->assertCreated()->assertJsonPath('width', 40);
});

// ── List, filters, sorts ────────────────────────────────────────────────────

it('lists with cursor pagination and a total', function (): void {
    Media::factory()->count(5)->create();

    $first = $this->getJson('/api/v1/admin/media?per_page=2')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.per_page', 2);

    $next = $first->json('meta.next_cursor');
    expect($next)->toBeString();

    $this->getJson('/api/v1/admin/media?per_page=2&cursor='.$next)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5);
});

it('filters by folder, unfiled, kind, missing alt and unused', function (): void {
    $blog = MediaFolder::systemId(MediaFolder::BLOG);
    $inProducts = Media::factory()->create(['folder_id' => $this->products]);
    $noBn = Media::factory()->create(['folder_id' => $blog, 'alt_text_bn' => null]);
    $noEn = Media::factory()->create(['alt_text' => '  ']);
    $pdf = Media::factory()->document()->create();
    productImageFor($inProducts);

    $ids = fn (string $query): array => collect($this->getJson('/api/v1/admin/media?'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids("filter[folder]={$this->products}"))->toBe([$inProducts->id])
        ->and($ids('filter[folder]=none'))->toBe([$noEn->id, $pdf->id])
        ->and($ids('filter[kind]=document'))->toBe([$pdf->id])
        ->and($ids('filter[missing_alt]=bn'))->toBe([$noBn->id])
        ->and($ids('filter[missing_alt]=en'))->toBe([$noEn->id])
        ->and($ids('filter[unused]=1'))->toBe([$noBn->id, $noEn->id, $pdf->id])
        ->and($ids("filter[unused]=1&filter[folder]={$blog}"))->toBe([$noBn->id]);
});

it('searches filename, alt text in both languages and the names of products using the file', function (): void {
    $byName = Media::factory()->create(['filename' => 'rc-racing-car-side.jpg']);
    $byAlt = Media::factory()->create(['alt_text' => 'Plush teddy bear']);
    $byBn = Media::factory()->create(['alt_text_bn' => 'কাঠের ট্রেন']);
    $byProduct = Media::factory()->create();
    productImageFor($byProduct, Product::factory()->create(['name' => 'Speed Cube 3x3', 'name_bn' => 'স্পিড কিউব']));

    $ids = fn (string $term): array => collect($this->getJson('/api/v1/admin/media?filter[search]='.urlencode($term))->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('racing'))->toBe([$byName->id])
        ->and($ids('TEDDY'))->toBe([$byAlt->id])
        ->and($ids('ট্রেন'))->toBe([$byBn->id])
        ->and($ids('speed cube'))->toBe([$byProduct->id])
        ->and($ids('স্পিড'))->toBe([$byProduct->id])
        ->and($ids('100%'))->toBe([]);
});

it('sorts by newest, oldest, name and size', function (): void {
    $a = Media::factory()->create(['filename' => 'b.jpg', 'original_size_bytes' => 300, 'created_at' => now()->subDays(2)]);
    $b = Media::factory()->create(['filename' => 'a.jpg', 'original_size_bytes' => 100, 'created_at' => now()->subDay()]);
    $c = Media::factory()->create(['filename' => 'c.jpg', 'original_size_bytes' => 200, 'created_at' => now()]);

    $order = fn (string $sort): array => collect($this->getJson('/api/v1/admin/media?sort='.$sort)->assertOk()->json('data'))->pluck('id')->all();

    expect($order('newest'))->toBe([$c->id, $b->id, $a->id])
        ->and($order('oldest'))->toBe([$a->id, $b->id, $c->id])
        ->and($order('name'))->toBe([$b->id, $a->id, $c->id])
        ->and($order('size'))->toBe([$a->id, $c->id, $b->id]);
});

it('refuses an unlisted filter or sort and invalid values with 400', function (string $query): void {
    $this->getJson('/api/v1/admin/media?'.$query)->assertStatus(400)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/bad-request');
})->with([
    'unknown filter' => 'filter[type]=video',
    'unknown sort' => 'sort=-created_at',
    'video kind' => 'filter[kind]=video',
    'bad folder' => 'filter[folder]=abc',
    'bad missing_alt' => 'filter[missing_alt]=fr',
    'bad unused' => 'filter[unused]=yes',
    'per_page over 100' => 'per_page=101',
]);

it('reports used_in_count per item', function (): void {
    $media = Media::factory()->create();
    productImageFor($media);
    productImageFor($media);

    $this->getJson('/api/v1/admin/media')->assertOk()->assertJsonPath('data.0.used_in_count', 2);
});

// ── Counts and storage ──────────────────────────────────────────────────────

it('counts the rail: folders, kinds, missing alt, unused, unfiled', function (): void {
    Media::factory()->create(['folder_id' => $this->products]);
    $used = Media::factory()->create(['folder_id' => $this->products, 'alt_text_bn' => null]);
    Media::factory()->create(['alt_text' => null]);
    Media::factory()->document()->create();
    productImageFor($used);

    $data = $this->getJson('/api/v1/admin/media/counts')->assertOk()->json('data');

    expect($data['all'])->toBe(4)
        ->and($data['unused'])->toBe(3)
        ->and($data['unfiled'])->toBe(2)
        ->and($data['kinds'])->toBe(['image' => 3, 'document' => 1])
        ->and($data['missing_alt'])->toBe(['bn' => 1, 'en' => 1])
        ->and(collect($data['folders'])->firstWhere('id', $this->products)['count'])->toBe(2)
        ->and(collect($data['folders'])->firstWhere('id', MediaFolder::systemId(MediaFolder::PAGES))['count'])->toBe(0);
});

it('reports storage: originals vs renditions, quota only when configured', function (): void {
    Media::factory()->create(['master_bytes' => 1000, 'renditions_bytes' => 4000]);
    Media::factory()->document()->create(['master_bytes' => 500]);

    $this->getJson('/api/v1/admin/media/storage')->assertOk()
        ->assertJsonPath('data.originals_bytes', 1500)
        ->assertJsonPath('data.renditions_bytes', 4000)
        ->assertJsonPath('data.used_bytes', 5500)
        ->assertJsonPath('data.file_count', 2)
        ->assertJsonPath('data.quota_bytes', null);

    config(['media.storage_quota_gb' => 10]);
    $this->getJson('/api/v1/admin/media/storage')->assertJsonPath('data.quota_bytes', 10737418240);
});

// ── Show, update ────────────────────────────────────────────────────────────

it('shows the detail with used_in and renditions', function (): void {
    $media = Media::factory()->create();
    $product = Product::factory()->create(['name' => 'Brick Box 500 pcs', 'name_bn' => 'ব্রিক বক্স ৫০০ পিস']);
    productImageFor($media, $product, 1);

    $this->getJson("/api/v1/admin/media/{$media->id}")->assertOk()
        ->assertJsonPath('used_in_count', 1)
        ->assertJsonPath('used_in.0.type', 'product_image')
        ->assertJsonPath('used_in.0.subject_type', 'product')
        ->assertJsonPath('used_in.0.subject_id', $product->id)
        ->assertJsonPath('used_in.0.title_bn', 'ব্রিক বক্স ৫০০ পিস')
        ->assertJsonPath('used_in.0.position', 2)
        ->assertJsonPath('used_in.0.is_primary', false)
        ->assertJsonPath('used_in.0.admin_path', "/admin/products/{$product->id}")
        ->assertJsonPath('renditions.0.format', 'webp')
        ->assertJsonPath('renditions.0.width', 160)
        ->assertJsonPath('renditions.0.bytes', 6400)
        ->assertJsonPath('renditions.0.url', fn (string $url): bool => str_ends_with($url, "/storage/{$media->base_path}/160.webp"))
        ->assertJsonPath('renditions.1.format', 'avif')
        ->assertJsonPath('download_path', "/api/v1/admin/media/{$media->id}/download");
});

it('updates alt text, caption and folder; only keys present change', function (): void {
    $media = Media::factory()->create(['caption' => 'keep']);

    $this->patchJson("/api/v1/admin/media/{$media->id}", ['alt_text_bn' => '  নতুন বাংলা বিবরণ ', 'folder_id' => $this->products])
        ->assertOk()
        ->assertJsonPath('alt_text_bn', 'নতুন বাংলা বিবরণ')
        ->assertJsonPath('caption', 'keep')
        ->assertJsonPath('folder_id', $this->products);

    $this->patchJson("/api/v1/admin/media/{$media->id}", ['alt_text' => '', 'folder_id' => null])
        ->assertOk()->assertJsonPath('alt_text', null)->assertJsonPath('alt_status', 'missing_en')->assertJsonPath('folder_id', null);

    $this->patchJson("/api/v1/admin/media/{$media->id}", ['folder_id' => 9999])->assertStatus(422)->assertJsonPath('errors.0.field', 'folder_id');
});

it('downloads the original as an attachment', function (): void {
    MediaKit::upload(MediaKit::pdf('Safety cert.pdf'))->assertCreated();
    $media = Media::query()->sole();

    $response = $this->get("/api/v1/admin/media/{$media->id}/download")->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('Safety-cert.pdf')
        ->and($response->streamedContent())->toBe(MediaKit::PDF);
});

// ── Replace ─────────────────────────────────────────────────────────────────

it('replaces the file: same id, new base, old files deleted after commit, usages follow', function (): void {
    MediaKit::upload(MediaKit::image('old.jpg', 500, 500), ['alt_text' => 'Front', 'alt_text_bn' => 'সামনে'])->assertCreated();
    $media = Media::query()->sole();
    $old = $media->base_path;
    $image = productImageFor($media);

    $this->post("/api/v1/admin/media/{$media->id}/replace", ['file' => MediaKit::image('new.png', 600, 300, 'png')], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('id', $media->id)
        ->assertJsonPath('filename', 'new.png')
        ->assertJsonPath('width', 600)
        ->assertJsonPath('height', 300)
        ->assertJsonPath('original_mime', 'image/png')
        ->assertJsonPath('alt_text_bn', 'সামনে')
        ->assertJsonPath('used_in_count', 1)
        ->assertJsonPath('processing_status', 'ready');

    $media->refresh();
    expect($media->base_path)->not->toBe($old);
    Storage::disk('public')->assertMissing("{$old}/160.webp");
    Storage::disk('local')->assertMissing("{$old}/master.webp");
    Storage::disk('public')->assertExists("{$media->base_path}/480.webp");

    // The product shows the new file at once, through the media.
    $image->refresh();
    expect($image->path)->toBe($media->base_path)
        ->and($image->width)->toBe(600)
        ->and($image->url(480))->toEndWith("{$media->base_path}/480.webp")
        ->and(ProductImage::query()->toBase()->where('id', $image->id)->value('path'))->toBe($media->base_path); // the mirror
});

it('queues the old base deletion after commit on replace', function (): void {
    Queue::fake();
    $media = Media::factory()->create(['base_path' => 'media/old']);

    $this->post("/api/v1/admin/media/{$media->id}/replace", ['file' => MediaKit::image()], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('processing_status', 'processing');

    Queue::assertPushed(App\Domain\Catalog\Jobs\DeleteMediaFiles::class, fn ($job): bool => $job->base === 'media/old' && $job->afterCommit === true);
});

it('refuses to replace an image with a PDF or a PDF with an image', function (): void {
    $image = Media::factory()->create();
    $pdf = Media::factory()->document()->create();

    $this->post("/api/v1/admin/media/{$image->id}/replace", ['file' => MediaKit::pdf()], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('errors.0.message', 'Replace with an image: this file is an image.');
    $this->post("/api/v1/admin/media/{$pdf->id}/replace", ['file' => MediaKit::image()], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('errors.0.message', 'Replace with a PDF: this file is a PDF.');
});

// ── Delete ──────────────────────────────────────────────────────────────────

it('deletes an unused file and its files after commit', function (): void {
    MediaKit::upload(MediaKit::image())->assertCreated();
    $media = Media::query()->sole();

    $this->deleteJson("/api/v1/admin/media/{$media->id}")->assertNoContent();

    expect(Media::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing("{$media->base_path}/master.webp");
    Storage::disk('public')->assertMissing("{$media->base_path}/160.webp");
});

it('refuses to delete a file in use with 409 media-in-use listing the usages', function (): void {
    $media = Media::factory()->create();
    productImageFor($media, Product::factory()->create(['name' => 'Wooden Rocker']));
    productImageFor($media, Product::factory()->create(['name' => 'Kids Tricycle']));

    $this->deleteJson("/api/v1/admin/media/{$media->id}")
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://toymartbd.com/problems/media-in-use')
        ->assertJsonPath('title', 'Media in use')
        ->assertJsonPath('detail', 'This file is used in 2 places. Remove it from them, or replace the file instead.')
        ->assertJsonCount(2, 'usages')
        ->assertJsonPath('usages.0.title', 'Kids Tricycle') // by product name
        ->assertJsonPath('usages.1.title', 'Wooden Rocker');

    expect(Media::query()->find($media->id))->not->toBeNull();
});

// ── Bulk ────────────────────────────────────────────────────────────────────

it('moves many files to a folder or un-files them', function (): void {
    $items = Media::factory()->count(3)->create();
    $ids = $items->pluck('id')->all();

    $this->postJson('/api/v1/admin/media/bulk/move', ['ids' => $ids, 'folder_id' => $this->products])
        ->assertOk()->assertJsonPath('data.moved', 3);
    expect(Media::query()->where('folder_id', $this->products)->count())->toBe(3);

    $this->postJson('/api/v1/admin/media/bulk/move', ['ids' => [$ids[0]], 'folder_id' => null])->assertOk()->assertJsonPath('data.moved', 1);
    $this->postJson('/api/v1/admin/media/bulk/move', ['ids' => [999999], 'folder_id' => null])->assertStatus(422)->assertJsonPath('errors.0.field', 'ids.0');
    $this->postJson('/api/v1/admin/media/bulk/move', ['ids' => $ids])->assertStatus(422)->assertJsonPath('errors.0.field', 'folder_id');
});

it('bulk-deletes what it can and skips files in use, per id in request order', function (): void {
    $free = Media::factory()->create();
    $busy = Media::factory()->create();
    $alsoFree = Media::factory()->create();
    productImageFor($busy);
    productImageFor($busy);

    $this->postJson('/api/v1/admin/media/bulk/delete', ['ids' => [$busy->id, $free->id, 999999, $alsoFree->id]])
        ->assertOk()
        ->assertJsonPath('data.deleted', 2)
        ->assertJsonPath('data.skipped', 2)
        ->assertJsonPath('data.results', [
            ['id' => $busy->id, 'status' => 'in_use', 'used_in_count' => 2],
            ['id' => $free->id, 'status' => 'deleted', 'used_in_count' => 0],
            ['id' => 999999, 'status' => 'not_found', 'used_in_count' => 0],
            ['id' => $alsoFree->id, 'status' => 'deleted', 'used_in_count' => 0],
        ]);

    expect(Media::query()->pluck('id')->all())->toBe([$busy->id]);
});

it('zips the originals with unique names', function (): void {
    MediaKit::upload(MediaKit::image('photo.jpg'))->assertCreated();
    MediaKit::upload(MediaKit::image('photo.png', 300, 300, 'png'))->assertCreated();
    MediaKit::upload(MediaKit::pdf('cert.pdf'))->assertCreated();
    $ids = Media::query()->orderBy('id')->pluck('id')->all();

    $response = $this->postJson('/api/v1/admin/media/bulk/download', ['ids' => $ids])->assertOk();
    $response->assertHeader('Content-Type', 'application/zip');
    expect($response->headers->get('Content-Disposition'))->toMatch('/attachment; filename=toymartbd-media-\d{8}-\d{4}\.zip/');

    $zip = new ZipArchive;
    $zip->open($response->getFile()->getPathname());
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    expect($names)->toBe(['photo.webp', 'photo (2).webp', 'cert.pdf'])
        ->and(ImagePipeline::magicType((string) $zip->getFromName('photo.webp')))->toBe('image/webp')
        ->and($zip->getFromName('cert.pdf'))->toBe(MediaKit::PDF);
    $zip->close();
});

it('refuses a zip over the file or byte cap with a clear 422', function (): void {
    config(['media.zip_max_files' => 2, 'media.zip_max_bytes' => 1048576]);
    $items = Media::factory()->count(3)->create(['master_bytes' => 10]);

    $this->postJson('/api/v1/admin/media/bulk/download', ['ids' => $items->pluck('id')->all()])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'ids')
        ->assertJsonPath('errors.0.message', '2 files at most per download; you selected 3.');

    $heavy = Media::factory()->count(2)->create(['master_bytes' => 700000]);
    $this->postJson('/api/v1/admin/media/bulk/download', ['ids' => $heavy->pluck('id')->all()])
        ->assertStatus(422)->assertJsonPath('errors.0.message', 'The selected files are 1.3 MB; the limit is 1 MB per download.');

    $this->postJson('/api/v1/admin/media/bulk/download', ['ids' => [999999]])->assertStatus(422)->assertJsonPath('errors.0.field', 'ids.0');
});

// ── Folders ─────────────────────────────────────────────────────────────────

it('lists system folders first, creates, renames and deletes custom folders', function (): void {
    $created = $this->postJson('/api/v1/admin/media/folders', ['name' => '  ঈদ ক্যাম্পেইন 2026 '])->assertCreated()
        ->assertJsonPath('name', 'ঈদ ক্যাম্পেইন 2026')
        ->assertJsonPath('is_system', false)
        ->assertJsonPath('system_key', null);
    $id = $created->json('id');

    $this->getJson('/api/v1/admin/media/folders')->assertOk()
        ->assertJsonPath('data.0.system_key', 'products')
        ->assertJsonPath('data.3.system_key', 'pages')
        ->assertJsonPath('data.4.id', $id);

    $this->postJson('/api/v1/admin/media/folders', ['name' => 'PRODUCTS'])->assertStatus(422)->assertJsonPath('errors.0.field', 'name');
    $this->patchJson("/api/v1/admin/media/folders/{$id}", ['name' => 'Eid 2026'])->assertOk()->assertJsonPath('name', 'Eid 2026');

    $media = Media::factory()->create(['folder_id' => $id]);
    $this->deleteJson("/api/v1/admin/media/folders/{$id}")->assertNoContent();
    expect($media->refresh()->folder_id)->toBeNull();
});

it('refuses to rename or delete a system folder with 409', function (): void {
    $this->patchJson("/api/v1/admin/media/folders/{$this->products}", ['name' => 'Photos'])
        ->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/conflict')
        ->assertJsonPath('detail', 'System folders cannot be renamed or deleted.');
    $this->deleteJson("/api/v1/admin/media/folders/{$this->products}")->assertStatus(409);
    expect(MediaFolder::query()->find($this->products)?->name)->toBe('Products');
});

// ── Stable public URL ───────────────────────────────────────────────────────

it('redirects /m/{id}/{name} to the current largest WebP rendition with a short cache, surviving replace', function (): void {
    MediaKit::upload(MediaKit::image('car.jpg', 500, 500))->assertCreated();
    $media = Media::query()->sole();

    $response = $this->get("/m/{$media->id}/anything.webp")->assertStatus(302);
    expect($response->headers->get('Location'))->toEndWith("{$media->base_path}/480.webp")
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=300')->toContain('public');

    $this->post("/api/v1/admin/media/{$media->id}/replace", ['file' => MediaKit::image('car2.jpg', 900, 900)], ['Accept' => 'application/json'])->assertOk();
    $media->refresh();

    expect($this->get("/m/{$media->id}/car.webp")->headers->get('Location'))->toEndWith("{$media->base_path}/800.webp");
});

it('returns 404 from /m for documents, unknown ids and images not ready', function (): void {
    $pdf = Media::factory()->document()->create();
    $processing = Media::factory()->create(['processing_status' => ImageProcessingStatus::Processing]);

    $this->get("/m/{$pdf->id}/cert.pdf")->assertNotFound();
    $this->get("/m/{$processing->id}/x.webp")->assertNotFound();
    $this->get('/m/999999/x.webp')->assertNotFound();
});
