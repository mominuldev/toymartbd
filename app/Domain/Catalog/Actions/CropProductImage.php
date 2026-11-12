<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Jobs\DeleteMediaFiles;
use App\Domain\Catalog\Jobs\GenerateImageRenditions;
use App\Domain\Catalog\Media\Enums\MediaOrigin;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Services\MediaUsages;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Services\ImagePipeline;
use App\Domain\Catalog\Services\MediaUrls;
use App\Domain\Catalog\Services\ProductImageMediaMirror;
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
    public function __construct(
        private readonly ImagePipeline $pipeline,
        private readonly MediaUsages $usages,
        private readonly ProductImageMediaMirror $mirror,
    ) {}

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

                if ($image->media_id !== null) {
                    $this->cropMedia($image, $base, $master);
                } else {
                    $old = (string) $image->path;

                    $image->fill([
                        'path' => $base,
                        'width' => $master['width'],
                        'height' => $master['height'],
                        'processing_status' => ImageProcessingStatus::Processing,
                        'rendition_widths' => null,
                    ])->save();

                    DeleteMediaFiles::dispatch($old);
                }

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

    /**
     * A library-linked image. Used only here: crop the media in place (new base, same
     * id, old files deleted after commit — like Replace). Shared with another product or
     * page: fork a new media row for this image, so the crop never changes the others.
     *
     * @param  array{bytes: string, width: int, height: int}  $master
     */
    private function cropMedia(ProductImage $image, string $base, array $master): void
    {
        $media = Media::query()->lockForUpdate()->findOrFail($image->media_id);
        $state = [
            'base_path' => $base,
            'width' => $master['width'],
            'height' => $master['height'],
            'processing_status' => ImageProcessingStatus::Processing,
            'master_bytes' => strlen($master['bytes']),
            'renditions' => null,
            'renditions_bytes' => 0,
            'size_bytes' => 0,
        ];

        if (count($this->usages->for($media->id)) <= 1) {
            $old = $media->base_path;
            $media->fill($state)->save();
            DeleteMediaFiles::dispatch($old);
        } else {
            $media = $media->replicate()->fill([...$state, 'origin' => MediaOrigin::Product]);
            $media->save();
            $image->media_id = $media->id;
            $image->save();
        }

        $this->mirror->sync($media, revalidate: false);
        $image->setRelation('media', $media);
    }
}
