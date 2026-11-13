<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Content\Models\Banner;
use App\Http\Resources\ApiResource;
use App\Http\Resources\Content\ContentFormat;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A banner in the admin. `state` (inactive | scheduled | live | ended) is computed on the
 * server against the current instant; schedule instants are UTC.
 *
 * @property Banner $resource
 */
#[SchemaName('Banner')]
final class BannerResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $banner = $this->resource;

        return [
            'id' => $banner->id,
            /** @var 'announcement_bar'|'category_page'|'info_strip'|'popup' */
            'placement' => $banner->placement->value,
            'name' => $banner->name,
            /** @var string|null */
            'title' => $banner->title,
            /** @var string|null */
            'title_bn' => $banner->title_bn,
            /** @var string|null */
            'body' => $banner->body,
            /** @var string|null */
            'body_bn' => $banner->body_bn,
            /** @var int|null */
            'media_id' => $banner->media_id,
            /** @var array{media_id: int, url: string, srcset: string|null, width: int|null, height: int|null, alt: string|null, alt_bn: string|null}|null */
            'image' => ContentFormat::image($banner->media, 640),
            /** @var string|null */
            'link_url' => $banner->link_url,
            /** @var string|null */
            'link_label' => $banner->link_label,
            /** @var string|null */
            'link_label_bn' => $banner->link_label_bn,
            /** @var int|null */
            'category_id' => $banner->category_id,
            /** @var array{id: int, name: string, name_bn: string|null}|null */
            'category' => $banner->category === null ? null : ['id' => $banner->category->id, 'name' => $banner->category->name, 'name_bn' => $banner->category->name_bn],
            'priority' => $banner->priority,
            'is_active' => $banner->is_active,
            /** @var string|null */
            'starts_at' => ContentFormat::utc($banner->starts_at),
            /** @var string|null */
            'ends_at' => ContentFormat::utc($banner->ends_at),
            /** @var 'inactive'|'scheduled'|'live'|'ended' */
            'state' => $banner->scheduleState(),
            /** @var string|null */
            'updated_at' => ContentFormat::utc($banner->updated_at),
        ];
    }
}
