<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Services\CategoryTree;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Storefront\StorefrontCategoryNodeResource;
use App\Http\Resources\Storefront\StorefrontCategoryResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Storefront categories — active only. Cached by tag on the Next.js side and
 * purged through docs/api/revalidation.md (`categories`, `category:{slug}`).
 */
final class CategoryController extends ApiController
{
    /**
     * `GET /categories/tree` — the mega menu: active categories with
     * `show_in_menu`, at most three levels, in tree order. A hidden or archived
     * category hides its whole branch.
     */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    public function tree(CategoryTree $tree): AnonymousResourceCollection
    {
        $nodes = Category::query()
            ->active()
            ->where('show_in_menu', true)
            ->inTreeOrder()
            ->get(['id', 'parent_id', '_lft', '_rgt', 'name', 'name_bn', 'slug', 'icon', 'image_path']);

        return StorefrontCategoryNodeResource::collection($tree->nest($nodes));
    }

    /**
     * `GET /categories/{slug}` — one active category with its breadcrumbs (root
     * first, itself last) and its active subcategories (menu visibility aside).
     */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'No active category has this slug')]
    public function show(string $slug, CategoryTree $tree): StorefrontCategoryResource
    {
        $category = Category::query()->active()->where('slug', $slug)->firstOrFail();
        $category->setRelation('ancestors', Category::query()->treeAncestorsOf($category)->inTreeOrder()->get());

        $below = Category::query()->active()->treeDescendantsOf($category)->inTreeOrder()->get();
        $category->setRelation('children', $tree->nest($below, Category::MAX_DEPTH - 1, $category->id));

        return new StorefrontCategoryResource($category);
    }
}
