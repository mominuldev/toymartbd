<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Services;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Enums\MediaKind;
use App\Domain\Catalog\Media\Enums\MediaOrigin;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Models\MediaFolder;
use App\Domain\Catalog\Services\MediaUrls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use stdClass;
use Throwable;

/**
 * Links every existing product image to a media row (expand step,
 * docs/api/media-library.md). One media row per distinct `product_images.path`, so a
 * duplicated product and its source share one row — and one alt text. Rows without a
 * file (`path` null) stay unlinked.
 *
 * Idempotent and migration-safe: walks `product_images` by id in chunks of 200, with
 * one short transaction per row (find-or-create the media by its unique `base_path`,
 * then set `media_id`), so it never holds a lock across the table and can be re-run or
 * resumed at any time. With `measure`, byte counts are read from the disks (outside
 * any transaction) for rows still at 0.
 *
 * Alt text: the first row (lowest id) with a value wins per language; later rows that
 * share the file fill only gaps.
 *
 * Plain query builder, not the ProductImage model, so a later model change cannot
 * break the migration that runs this.
 */
final class ProductImageMediaBackfill
{
    /** @return array{linked: int, created: int, measured: int} */
    public function run(bool $measure = true): array
    {
        $folderId = $this->productsFolder();
        $linked = 0;
        $created = 0;

        DB::table('product_images')
            ->whereNull('media_id')
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($folderId, &$linked, &$created): void {
                foreach ($rows as $row) {
                    /** @var stdClass $row */
                    DB::transaction(function () use ($row, $folderId, &$linked, &$created): void {
                        $mediaId = DB::table('media')->where('base_path', $row->path)->lockForUpdate()->value('id');

                        if (! is_numeric($mediaId)) {
                            $mediaId = DB::table('media')->insertGetId($this->mediaFor($row, $folderId));
                            $created++;
                        } else {
                            $this->fillAltGaps((int) $mediaId, $row);
                        }

                        $linked += DB::table('product_images')->where('id', $row->id)->whereNull('media_id')->update(['media_id' => (int) $mediaId]);
                    });
                }
            });

        return ['linked' => $linked, 'created' => $created, 'measured' => $measure ? $this->measure() : 0];
    }

    /** @return array<string, mixed> */
    private function mediaFor(stdClass $row, int $folderId): array
    {
        $widths = is_string($row->rendition_widths) ? json_decode($row->rendition_widths, true) : null;
        $renditions = null;

        if (is_array($widths)) {
            $renditions = [];
            foreach ($widths as $w) {
                foreach (MediaUrls::formats() as $f) {
                    $renditions[] = ['w' => (int) $w, 'f' => $f, 'b' => 0];
                }
            }
        }

        $now = now();

        return [
            'kind' => MediaKind::Image->value,
            'folder_id' => $folderId,
            'filename' => MediaFiles::productFallbackName((int) $row->product_id, (int) $row->position),
            'alt_text' => self::clean($row->alt_text),
            'alt_text_bn' => self::clean($row->alt_text_bn),
            'base_path' => $row->path,
            'mime' => 'image/webp',
            'original_mime' => 'image/webp', // the upload's real type was not recorded before the library
            'processing_status' => is_string($row->processing_status) ? $row->processing_status : ImageProcessingStatus::Ready->value,
            'width' => $row->width,
            'height' => $row->height,
            'renditions' => $renditions === null ? null : json_encode($renditions, JSON_THROW_ON_ERROR),
            'origin' => MediaOrigin::Product->value,
            'uploaded_by' => null,
            'created_at' => $row->created_at ?? $now,
            'updated_at' => $now,
        ];
    }

    private function fillAltGaps(int $mediaId, stdClass $row): void
    {
        foreach (['alt_text', 'alt_text_bn'] as $key) {
            $value = self::clean($row->{$key});

            if ($value !== null) {
                DB::table('media')->where('id', $mediaId)
                    ->where(static fn ($q) => $q->whereNull($key)->orWhere($key, ''))
                    ->update([$key => $value]);
            }
        }
    }

    /** Record byte counts for media still at 0. Disk reads only; one tiny update per row. */
    private function measure(): int
    {
        $count = 0;
        $originals = Storage::disk(MediaUrls::originalsDisk());
        $public = Storage::disk(MediaUrls::disk());

        Media::query()->where('kind', MediaKind::Image->value)->where('master_bytes', 0)->orderBy('id')
            ->chunkById(200, function ($rows) use ($originals, $public, &$count): void {
                foreach ($rows as $media) {
                    /** @var Media $media */
                    try {
                        $master = $originals->exists($media->originalPath()) ? $originals->size($media->originalPath()) : 0;
                        $renditions = [];
                        $bytes = 0;
                        $served = 0;

                        foreach ($media->renditions ?? [] as $r) {
                            $path = MediaUrls::renditionPath($media->base_path, $r['w'], $r['f']);
                            $size = $public->exists($path) ? $public->size($path) : 0;
                            $renditions[] = ['w' => $r['w'], 'f' => $r['f'], 'b' => $size];
                            $bytes += $size;
                            $served = $r['f'] === 'webp' ? $size : $served;
                        }
                    } catch (Throwable) {
                        continue;
                    }

                    if ($master === 0 && $bytes === 0) {
                        continue;
                    }

                    Media::query()->whereKey($media->id)->update([
                        'master_bytes' => $master,
                        'original_size_bytes' => $media->original_size_bytes > 0 ? $media->original_size_bytes : $master,
                        'renditions' => $renditions === [] ? null : json_encode($renditions, JSON_THROW_ON_ERROR),
                        'renditions_bytes' => $bytes,
                        'size_bytes' => $served,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function productsFolder(): int
    {
        $id = MediaFolder::systemId(MediaFolder::PRODUCTS);

        if ($id !== null) {
            return $id;
        }

        [$name, $position] = MediaFolder::SYSTEM[MediaFolder::PRODUCTS];

        return MediaFolder::query()->create([
            'name' => $name, 'name_key' => MediaFolder::key($name), 'system_key' => MediaFolder::PRODUCTS, 'position' => $position,
        ])->id;
    }

    private static function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
