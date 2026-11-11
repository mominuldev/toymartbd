<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Jobs\DeleteMediaFiles;
use App\Domain\Catalog\Jobs\GenerateImageRenditions;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Services\ImagePipeline;
use App\Domain\Catalog\Services\MediaUrls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Crop an existing image (the uploader's crop button). The rectangle is in the
 * current master's pixels (`width` × `height` on the resource). Files are
 * immutable, so the cropped master goes to a new base, renditions regenerate after
 * commit, and the old files are removed after commit.
 */
final class CropProductImage
{
    public function __construct(private readonly ImagePipeline $pipeline) {}

    /** @param array{x: int, y: int, width: int, height: int} $crop */
    public function handle(ProductImage $image, array $crop): ProductImage
    {
        if ($image->path === null) {
            throw ValidationException::withMessages(['crop' => 'This image has no file to crop.']);
        }

        $originals = Storage::disk(MediaUrls::originalsDisk());
        $bytes = $originals->get("{$image->path}/master.webp");

        if ($bytes === null) {
            throw ValidationException::withMessages(['crop' => 'The original of this image is missing; upload it again.']);
        }

        $master = $this->pipeline->recrop($bytes, $crop);
        $base = "products/{$image->product_id}/".Str::lower((string) Str::ulid());
        $originals->put("{$base}/master.webp", $master['bytes']);

        try {
            $image = DB::transaction(function () use ($image, $base, $master): ProductImage {
                $product = Product::query()->lockForUpdate()->findOrFail($image->product_id);
                $image = ProductImage::query()->lockForUpdate()->findOrFail($image->id);
                $old = (string) $image->path;

                $image->fill([
                    'path' => $base,
                    'width' => $master['width'],
                    'height' => $master['height'],
                    'processing_status' => ImageProcessingStatus::Processing,
                    'rendition_widths' => null,
                ])->save();

                DeleteMediaFiles::dispatch($old);
                ProductChanged::dispatch($product->id, $product->slug);

                return $image;
            });
        } catch (Throwable $e) {
            $originals->deleteDirectory($base);

            throw $e;
        }

        GenerateImageRenditions::dispatch($image->id);

        return $image->refresh();
    }
}
