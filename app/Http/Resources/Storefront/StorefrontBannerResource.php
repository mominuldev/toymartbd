<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Content\Models\Banner;
use App\Http\Resources\ApiResource;
use App\Http\Resources\Content\ContentFormat;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A live banner. `ends_at` lets the page shorten its cache to the banner's end; the schedule
 * command purges `content:banners` at every boundary anyway.
 *
 * @property Banner $resource
 */
#[SchemaName('StorefrontBanner')]
final class StorefrontBannerResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $b = $this->resource;

        return [
            'id' => $b->id,
            /** @var 'announcement_bar'|'category_page'|'info_strip'|'popup' */
            'placement' => $b->placement->value,
            /** @var string|null */
            'title' => $b->title,
            /** @var string|null */
            'title_bn' => $b->title_bn,
            /** @var string|null */
            'body' => $b->body,
            /** @var string|null */
            'body_bn' => $b->body_bn,
            /** @var array{media_id: int, url: string, srcset: string|null, width: int|null, height: int|null, alt: string|null, alt_bn: string|null}|null */
            'image' => $b->placement->allowsImage() ? ContentFormat::image($b->media) : null,
            /** @var string|null */
            'link_url' => $b->link_url,
            /** @var string|null */
            'link_label' => $b->link_label,
            /** @var string|null */
            'link_label_bn' => $b->link_label_bn,
            /** @var string|null */
            'ends_at' => ContentFormat::utc($b->ends_at),
        ];
    }
}
