<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Actions;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Enums\MediaKind;
use App\Domain\Catalog\Media\Enums\MediaOrigin;
use App\Domain\Catalog\Media\Jobs\GenerateMediaRenditions;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Services\MediaFiles;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Add one file to the media library (docs/api/media-library.md `POST /media`).
 *
 * 1. Outside any transaction: sniff, validate and write the original (an image's
 *    sanitised master, or the PDF) to the private originals disk under a new base.
 * 2. One short transaction: insert the row (`processing` for an image).
 * 3. After commit: GenerateMediaRenditions writes the renditions (rule 4).
 *
 * Missing alt text never blocks an upload (A25 rule 1).
 */
final readonly class UploadMedia
{
    public function __construct(private MediaFiles $files) {}

    /**
     * @param  array{folder_id?: int|null, alt_text?: string|null, alt_text_bn?: string|null, caption?: string|null}  $attributes
     */
    public function handle(string $uploadedPath, string $clientName, array $attributes = [], ?int $userId = null): Media
    {
        $stored = $this->files->store($uploadedPath, 'media');
        $isImage = $stored['kind'] === MediaKind::Image;

        try {
            $media = DB::transaction(static fn (): Media => Media::query()->create([
                'kind' => $stored['kind'],
                'folder_id' => $attributes['folder_id'] ?? null,
                'filename' => MediaFiles::displayName($clientName, $stored['kind']),
                'caption' => self::clean($attributes['caption'] ?? null),
                'alt_text' => $isImage ? self::clean($attributes['alt_text'] ?? null) : null,
                'alt_text_bn' => $isImage ? self::clean($attributes['alt_text_bn'] ?? null) : null,
                'base_path' => $stored['base'],
                'mime' => $stored['mime'],
                'original_mime' => $stored['original_mime'],
                'processing_status' => $isImage ? ImageProcessingStatus::Processing : ImageProcessingStatus::Ready,
                'width' => $stored['width'],
                'height' => $stored['height'],
                'original_size_bytes' => $stored['original_size'],
                'master_bytes' => $stored['master_bytes'],
                'renditions_bytes' => 0,
                'size_bytes' => $isImage ? 0 : $stored['master_bytes'],
                'renditions' => null,
                'origin' => MediaOrigin::Library,
                'uploaded_by' => $userId,
            ]));
        } catch (Throwable $e) {
            MediaFiles::discard($stored['base']);

            throw $e;
        }

        if ($isImage) {
            GenerateMediaRenditions::dispatch($media->id);
        }

        return $media->refresh();
    }

    public static function clean(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
