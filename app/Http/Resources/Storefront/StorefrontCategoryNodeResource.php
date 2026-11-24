<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Catalog\Models\Category;
use App\Http\Resources\Admin\CategoryResource;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One node of the storefront category tree (the mega menu):
 * `{ id, name, name_bn, slug, icon, image_url, tagline, tagline_bn, children: [same shape] }` —
 * `tagline` is the short line under the home category tile (Smart Junior 34:5709).
 * The storefront builds its menu against exactly this shape — additive changes only.
 *
 * @property Category $resource
 */
#[SchemaName('StorefrontCategoryNode')]
final class StorefrontCategoryNodeResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->resource;

        return [
            'id' => $category->id,
            'name' => $category->name,
            /** @var string|null */
            'name_bn' => $category->name_bn,
            'slug' => $category->slug,
            /** @var string|null */
            'icon' => $category->icon,
            /** @var string|null */
            'image_url' => CategoryResource::imageUrl($category),
            /** @var string|null */
            'tagline' => $category->tagline,
            /** @var string|null */
            'tagline_bn' => $category->tagline_bn,
            /** @var list<StorefrontCategoryNodeResource> */
            'children' => self::collection($category->relationLoaded('children') ? $category->children : []),
        ];
    }
}
