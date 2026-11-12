<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Media\Models\Media;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A media-library file (docs/api/media-library.md `Media`). `urls`, `srcset` and
 * `public_url` are null for documents and until an image's renditions are ready.
 * Disk paths of originals are never exposed.
 *
 * @property Media $resource
 */
#[SchemaName('Media')]
class MediaResource extends ApiResource
{
    /** Relations every Media response reads. */
    public const array RELATIONS = ['uploader:id,name'];

    /**
     * @return array{id: int, kind: 'image'|'document', filename: string, caption: string|null, alt_text: string|null, alt_text_bn: string|null, alt_status: 'complete'|'missing_bn'|'missing_en'|'missing'|'not_applicable', folder_id: int|null, mime: string, original_mime: string, width: int|null, height: int|null, size_bytes: int, original_size_bytes: int, processing_status: 'processing'|'ready'|'failed', urls: array{thumb: string|null, medium: string|null, large: string|null}, srcset: array{webp: string|null, avif: string|null}, public_url: string|null, used_in_count: int, origin: 'library'|'product', uploaded_by: array{id: int, name: string}|null, created_at: string|null, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return self::base($this->resource);
    }

    /**
     * @return array{id: int, kind: 'image'|'document', filename: string, caption: string|null, alt_text: string|null, alt_text_bn: string|null, alt_status: 'complete'|'missing_bn'|'missing_en'|'missing'|'not_applicable', folder_id: int|null, mime: string, original_mime: string, width: int|null, height: int|null, size_bytes: int, original_size_bytes: int, processing_status: 'processing'|'ready'|'failed', urls: array{thumb: string|null, medium: string|null, large: string|null}, srcset: array{webp: string|null, avif: string|null}, public_url: string|null, used_in_count: int, origin: 'library'|'product', uploaded_by: array{id: int, name: string}|null, created_at: string|null, updated_at: string|null}
     */
    protected static function base(Media $media): array
    {
        /** @var array{thumb: int, medium: int, large: int} $sizes */
        $sizes = config('media.sizes');
        $uploader = $media->uploaded_by !== null ? $media->uploader : null;

        return [
            'id' => $media->id,
            /** @var 'image'|'document' */
            'kind' => $media->kind->value,
            'filename' => $media->filename,
            'caption' => $media->caption,
            'alt_text' => $media->alt_text,
            'alt_text_bn' => $media->alt_text_bn,
            /** @var 'complete'|'missing_bn'|'missing_en'|'missing'|'not_applicable' */
            'alt_status' => $media->altStatus()->value,
            'folder_id' => $media->folder_id,
            'mime' => $media->mime,
            'original_mime' => $media->original_mime,
            'width' => $media->width,
            'height' => $media->height,
            'size_bytes' => $media->size_bytes,
            'original_size_bytes' => $media->original_size_bytes,
            /** @var 'processing'|'ready'|'failed' */
            'processing_status' => $media->processing_status->value,
            'urls' => [
                'thumb' => $media->url($sizes['thumb']),
                'medium' => $media->url($sizes['medium']),
                'large' => $media->url($sizes['large']),
            ],
            'srcset' => [
                'webp' => $media->srcset('webp'),
                'avif' => $media->srcset('avif'),
            ],
            'public_url' => $media->publicUrl(),
            'used_in_count' => $media->usedInCount ?? 0,
            /** @var 'library'|'product' */
            'origin' => $media->origin->value,
            'uploaded_by' => $uploader !== null ? ['id' => $uploader->id, 'name' => $uploader->name] : null,
            'created_at' => $media->created_at?->toIso8601String(),
            'updated_at' => $media->updated_at?->toIso8601String(),
        ];
    }
}
