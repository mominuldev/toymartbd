<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Actions;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Jobs\DeleteMediaFiles;
use App\Domain\Catalog\Media\Jobs\GenerateMediaRenditions;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Services\MediaFiles;
use App\Domain\Catalog\Services\ProductImageMediaMirror;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Replace a media row's file (docs/api/media-library.md, decision 2): the new file goes
 * to a NEW base under the SAME row — rendition paths stay immutable — so every usage,
 * which references the id, updates at once. Alt text, caption, folder, origin and
 * usages are kept. The old base is deleted after commit; the new renditions are
 * generated after commit.
 */
final readonly class ReplaceMedia
{
    public function __construct(
        private MediaFiles $files,
        private ProductImageMediaMirror $mirror,
    ) {}

    public function handle(Media $media, string $uploadedPath, string $clientName): Media
    {
        $stored = $this->files->store($uploadedPath, self::prefixOf($media->base_path), $media->kind);
        $isImage = $media->isImage();

        try {
            $media = DB::transaction(function () use ($media, $stored, $clientName, $isImage): Media {
                $media = Media::query()->lockForUpdate()->findOrFail($media->id);
                $old = $media->base_path;

                $media->fill([
                    'filename' => MediaFiles::displayName($clientName, $media->kind),
                    'base_path' => $stored['base'],
                    'original_mime' => $stored['original_mime'],
                    'processing_status' => $isImage ? ImageProcessingStatus::Processing : ImageProcessingStatus::Ready,
                    'width' => $stored['width'],
                    'height' => $stored['height'],
                    'original_size_bytes' => $stored['original_size'],
                    'master_bytes' => $stored['master_bytes'],
                    'renditions_bytes' => 0,
                    'size_bytes' => $isImage ? 0 : $stored['master_bytes'],
                    'renditions' => null,
                ])->save();

                $this->mirror->sync($media);
                DeleteMediaFiles::dispatch($old);

                return $media;
            });
        } catch (Throwable $e) {
            MediaFiles::discard($stored['base']);

            throw $e;
        }

        if ($isImage) {
            GenerateMediaRenditions::dispatch($media->id);
        }

        return $media->refresh();
    }

    /** `products/12/01h…` → `products/12`; `media/01h…` → `media`. */
    private static function prefixOf(string $base): string
    {
        $dir = dirname($base);

        return $dir === '.' || $dir === '' ? 'media' : $dir;
    }
}
