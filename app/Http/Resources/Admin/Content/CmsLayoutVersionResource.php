<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Content;

use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One published version in the history. `is_live` marks the current one; `restored_from_version`
 * is set when the version was created by reverting to an older one.
 *
 * @property CmsLayoutVersion $resource
 */
#[SchemaName('CmsLayoutVersion')]
final class CmsLayoutVersionResource extends ApiResource
{
    public function __construct(CmsLayoutVersion $resource, private readonly ?int $liveVersion = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array{id: int, version: int, block_count: int, published_at: string|null, published_by: array{id: int, name: string}|null, restored_from_version: int|null, note: string|null, is_live: bool}
     */
    public function toArray(Request $request): array
    {
        $version = $this->resource;
        $publisher = $version->publisher;

        return [
            'id' => $version->id,
            'version' => $version->version,
            'block_count' => $version->block_count,
            /** @var string|null */
            'published_at' => CmsBlockResource::iso($version->published_at),
            /** @var array{id: int, name: string}|null */
            'published_by' => $publisher === null ? null : ['id' => $publisher->id, 'name' => $publisher->name],
            'restored_from_version' => $version->restored_from_version,
            'note' => $version->note,
            'is_live' => $this->liveVersion === $version->version,
        ];
    }
}
