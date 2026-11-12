<?php

declare(strict_types=1);

use App\Domain\Catalog\Media\Enums\MediaOrigin;
use App\Domain\Catalog\Media\Models\MediaFolder;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Product;
use Database\Seeders\BrandDemoSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * Demo photos land in the library under generic product-type filenames — never a brand
 * (media-library lead decision 5) — while the catalogue's product names stay as they are.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

it('names every demo photo generically, with no brand in any filename', function (): void {
    $files = glob(base_path(DemoCatalogSeeder::IMAGE_DIR).'/[0-9][0-9]-*.png') ?: [];
    $skus = array_map(static fn (string $f): string => (string) preg_replace('/^\d\d-|\.png$/', '', basename($f)), $files);

    expect(array_keys(DemoCatalogSeeder::MEDIA_FILENAMES))->toEqualCanonicalizing($skus);

    $this->seed(BrandDemoSeeder::class);
    $brands = Brand::query()->pluck('slug')->merge(['rubik', 'play-doh', 'barbie', 'crayola', 'melissa', 'doug'])->all();

    foreach (DemoCatalogSeeder::MEDIA_FILENAMES as $filename) {
        expect($filename)->toMatch('/^[a-z0-9-]+-\d{2}\.webp$/');
        foreach ($brands as $brand) {
            // Whole hyphen-delimited words: "shape" is not the brand "hape".
            expect(str_contains('-'.$filename.'-', '-'.$brand.'-'))->toBeFalse("{$filename} carries the brand {$brand}");
        }
    }
});

it('files a seeded demo photo as a Products-folder media row with its generic name', function (): void {
    Storage::fake('public');
    Storage::fake('local');
    $this->seed(DemoCatalogSeeder::class);

    $product = Product::query()->where('slug', 'melissa-doug-wooden-doctor-kit')->first() ?? Product::query()->firstOrFail();
    $image = (new DemoCatalogSeeder)->attachImage($product, 'TMB-MEL-DOCK', $product->name, (string) $product->name_bn);
    $media = $image->media;

    expect($media?->filename)->toBe('wooden-doctor-kit-01.webp')
        ->and($media?->origin)->toBe(MediaOrigin::Product)
        ->and($media?->folder_id)->toBe(MediaFolder::systemId(MediaFolder::PRODUCTS))
        ->and($media?->uploaded_by)->toBeNull();
});
