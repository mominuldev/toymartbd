<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Content\Models\CmsPage;
use App\Http\Resources\ApiResource;
use App\Http\Resources\Content\ContentFormat;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A live page in the list (sitemaps, footers): `{ slug, title, title_bn, updated_at }`.
 *
 * @property CmsPage $resource
 */
#[SchemaName('StorefrontPageSummary')]
final class StorefrontPageSummaryResource extends ApiResource
{
    /** @return array{slug: string, title: string, title_bn: string|null, updated_at: string|null} */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->resource->slug,
            'title' => $this->resource->title,
            'title_bn' => $this->resource->title_bn,
            'updated_at' => ContentFormat::utc($this->resource->updated_at),
        ];
    }
}
