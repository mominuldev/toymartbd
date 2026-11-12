<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Actions;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Services\ProductImageMediaMirror;
use Illuminate\Support\Facades\DB;

/**
 * Record that a media row's renditions exist (`ready`, the widths and byte counts), or
 * that generation failed. Only applies when the row still points at the base the
 * renditions were written for: a Replace that landed meanwhile wins, and its own job
 * completes it. Every product showing the file is revalidated.
 */
final readonly class CompleteMediaRenditions
{
    public function __construct(private ProductImageMediaMirror $mirror) {}

    /**
     * @param  array{widths: list<int>, renditions: list<array{w: int, f: string, b: int}>, bytes: int, served: int}  $result
     */
    public function handle(int $mediaId, string $base, array $result): void
    {
        DB::transaction(function () use ($mediaId, $base, $result): void {
            $media = Media::query()->lockForUpdate()->find($mediaId);

            if ($media === null || $media->base_path !== $base) {
                return;
            }

            $media->fill([
                'processing_status' => ImageProcessingStatus::Ready,
                'renditions' => $result['renditions'],
                'renditions_bytes' => $result['bytes'],
                'size_bytes' => $result['served'],
            ])->save();

            $this->mirror->sync($media);
        });
    }

    public function fail(int $mediaId): void
    {
        DB::transaction(function () use ($mediaId): void {
            $media = Media::query()->lockForUpdate()->find($mediaId);

            if ($media === null || $media->processing_status !== ImageProcessingStatus::Processing) {
                return;
            }

            $media->processing_status = ImageProcessingStatus::Failed;
            $media->save();

            $this->mirror->sync($media, revalidate: false);
        });
    }
}
