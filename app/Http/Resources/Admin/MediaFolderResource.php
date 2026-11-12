<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Media\Models\MediaFolder;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A media-library folder. System folders carry `system_key`; the admin translates
 * their name by that key, and shows custom names as typed.
 *
 * @property MediaFolder $resource
 */
#[SchemaName('MediaFolder')]
final class MediaFolderResource extends ApiResource
{
    /**
     * @return array{id: int, name: string, system_key: 'products'|'category_banners'|'blog'|'pages'|null, is_system: bool, position: int, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        $folder = $this->resource;

        return [
            'id' => $folder->id,
            'name' => $folder->name,
            /** @var 'products'|'category_banners'|'blog'|'pages'|null */
            'system_key' => $folder->system_key,
            'is_system' => $folder->isSystem(),
            'position' => $folder->position,
            'created_at' => $folder->created_at?->toIso8601String(),
        ];
    }
}
