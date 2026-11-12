<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Media\DTOs\MediaUsage;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Services\MediaUrls;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A media file with where it is used and its renditions — the detail panel
 * (docs/api/media-library.md `MediaDetail`).
 *
 * @property Media $resource
 */
#[SchemaName('MediaDetail')]
final class MediaDetailResource extends MediaResource
{
    /** @param list<MediaUsage> $usages */
    public function __construct(Media $media, private readonly array $usages = [])
    {
        $media->usedInCount = count($usages);
        parent::__construct($media);
    }

    /**
     * @return array{id: int, kind: 'image'|'document', filename: string, caption: string|null, alt_text: string|null, alt_text_bn: string|null, alt_status: 'complete'|'missing_bn'|'missing_en'|'missing'|'not_applicable', folder_id: int|null, mime: string, original_mime: string, width: int|null, height: int|null, size_bytes: int, original_size_bytes: int, processing_status: 'processing'|'ready'|'failed', urls: array{thumb: string|null, medium: string|null, large: string|null}, srcset: array{webp: string|null, avif: string|null}, public_url: string|null, used_in_count: int, origin: 'library'|'product', uploaded_by: array{id: int, name: string}|null, created_at: string|null, updated_at: string|null, used_in: list<array{type: string, subject_type: string, subject_id: int, title: string, title_bn: string|null, position: int|null, is_primary: bool|null, admin_path: string}>, renditions: list<array{format: string, width: int, bytes: int, url: string}>, master_bytes: int, renditions_bytes: int, download_path: string}
     */
    public function toArray(Request $request): array
    {
        $media = $this->resource;
        $renditions = [];

        if ($media->url() !== null) {
            $list = $media->renditions ?? [];
            usort($list, static fn (array $a, array $b): int => [$a['w'], $a['f'] === 'webp' ? 0 : 1] <=> [$b['w'], $b['f'] === 'webp' ? 0 : 1]);

            foreach ($list as $r) {
                $renditions[] = [
                    'format' => $r['f'],
                    'width' => (int) $r['w'],
                    'bytes' => (int) $r['b'],
                    'url' => MediaUrls::url(MediaUrls::renditionPath($media->base_path, (int) $r['w'], $r['f'])),
                ];
            }
        }

        return [
            ...self::base($media),
            'used_in' => array_map(MediaUsageResource::shape(...), $this->usages),
            'renditions' => $renditions,
            'master_bytes' => $media->master_bytes,
            'renditions_bytes' => $media->renditions_bytes,
            'download_path' => '/api/v1/admin/media/'.$media->id.'/download',
        ];
    }
}
