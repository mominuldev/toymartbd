<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Catalog\Models\Category;
use App\Http\Resources\Admin\CategoryResource;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A category page on the storefront: the category, its `breadcrumbs` (root first,
 * ending with itself) and its active `children` as tree nodes.
 *
 * @property Category $resource
 */
#[SchemaName('StorefrontCategory')]
final class StorefrontCategoryResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->resource;

        /** @var iterable<Category> $ancestors */
        $ancestors = $category->relationLoaded('ancestors') ? $category->ancestors : [];
        $breadcrumbs = [];
        foreach ([...$ancestors, $category] as $crumb) {
            $breadcrumbs[] = ['name' => $crumb->name, 'name_bn' => $crumb->name_bn, 'slug' => $crumb->slug];
        }

        return [
            'id' => $category->id,
            'name' => $category->name,
            /** @var string|null */
            'name_bn' => $category->name_bn,
            'slug' => $category->slug,
            /** @var string|null */
            'description' => $category->description,
            /** @var string|null */
            'description_bn' => $category->description_bn,
            /** @var string|null */
            'icon' => $category->icon,
            /** @var string|null */
            'image_url' => CategoryResource::publicUrl($category->image_path),
            /** @var string|null */
            'banner_url' => CategoryResource::publicUrl($category->banner_path),
            /** @var list<array{name: string, name_bn: string|null, slug: string}> */
            'breadcrumbs' => $breadcrumbs,
            /** @var list<StorefrontCategoryNodeResource> */
            'children' => StorefrontCategoryNodeResource::collection($category->relationLoaded('children') ? $category->children : []),
        ];
    }
}
