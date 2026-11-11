<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Actions\ArchiveCategory;
use App\Domain\Catalog\Actions\CreateCategory;
use App\Domain\Catalog\Actions\MoveCategory;
use App\Domain\Catalog\Actions\UpdateCategory;
use App\Domain\Catalog\Enums\CategoryStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Services\CategoryTree;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Category\MoveCategoryRequest;
use App\Http\Requests\Admin\Category\StoreCategoryRequest;
use App\Http\Requests\Admin\Category\UpdateCategoryRequest;
use App\Http\Resources\Admin\CategoryResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Categories (P4-CAT-08): a nested set at most three levels deep. Archive, never
 * delete: `DELETE` archives, and is a 409 while active subcategories remain.
 * Reparenting and reordering go through `POST /categories/{category}/move`.
 */
final class CategoryController extends ApiController
{
    public const int PER_PAGE = 25;

    /** The whole tree is returned in one response; categories are few by nature. */
    public const int MAX_TREE = 2000;

    /**
     * `GET /categories` — needs catalog.view.
     *
     * `?tree=1` returns the whole tree, archived categories included, as
     * `{ data: Category[] }` where each node carries `children` recursively, in tree
     * order. Filters and sorts do not apply to the tree.
     *
     * Otherwise a cursor-paginated flat list in tree order. Filters:
     * `filter[search]` (name, name_bn or slug contains), `filter[status]` =
     * `active|archived`, `filter[parent_id]` = an id or `root`. Sorts: `name`,
     * `-name`, `created_at`, `-created_at` (default: tree order). Anything else is a 400.
     */
    #[QueryParameter('tree', 'Return the full nested tree instead of a paginated list.', type: "'1'|'0'")]
    #[QueryParameter('filter[search]', 'Case-insensitive "contains" match on name, name_bn or slug.', type: 'string')]
    #[QueryParameter('filter[status]', 'Only active or only archived categories.', type: "'active'|'archived'")]
    #[QueryParameter('filter[parent_id]', 'Direct children of this category id, or `root` for top-level categories.', type: 'string')]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending. Default: tree order.', type: "'name'|'-name'|'created_at'|'-created_at'")]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request, CategoryTree $tree): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Category::class);

        if ($request->boolean('tree')) {
            if ($request->hasAny(['filter', 'sort', 'cursor'])) {
                throw new BadRequestHttpException('Filters, sorts and cursors do not apply to ?tree=1.');
            }

            $all = Category::query()->withTreeDepth()->inTreeOrder()->limit(self::MAX_TREE)->get();

            return CategoryResource::collection($tree->nest($all, PHP_INT_MAX));
        }

        $sort = $request->query('sort');
        $descending = is_string($sort) && str_starts_with($sort, '-');

        $categories = QueryBuilder::for(Category::query()->withTreeDepth(), $request)
            ->allowedFilters(
                AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                    $term = is_array($value) ? implode(',', array_map(strval(...), $value)) : (string) $value;
                    $like = '%'.addcslashes($term, '\\%_').'%';

                    $query->where(static function (Builder $q) use ($like): void {
                        $q->where('categories.name', 'like', $like)
                            ->orWhere('categories.name_bn', 'like', $like)
                            ->orWhere('categories.slug', 'like', $like);
                    });
                }),
                AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                    $status = is_string($value) ? CategoryStatus::tryFrom($value) : null;

                    if ($status === null) {
                        throw new BadRequestHttpException('filter[status] must be one of: active, archived.');
                    }

                    $query->where('categories.status', $status->value);
                }),
                AllowedFilter::callback('parent_id', static function (Builder $query, mixed $value): void {
                    if ($value === 'root') {
                        $query->whereNull('categories.parent_id');

                        return;
                    }

                    if (! is_string($value) || ! ctype_digit($value)) {
                        throw new BadRequestHttpException('filter[parent_id] must be a category id or "root".');
                    }

                    $query->where('categories.parent_id', (int) $value);
                }),
            )
            ->allowedSorts('name', 'created_at')
            ->defaultSort('_lft')
            // A unique tiebreaker keeps cursor pagination stable.
            ->orderBy('categories.id', $descending ? 'desc' : 'asc')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return CategoryResource::collection($categories);
    }

    /** `POST /categories` — needs catalog.create. */
    #[OpenApiResponse(409, 'The parent is archived (`conflict`)')]
    public function store(StoreCategoryRequest $request, CreateCategory $createCategory): JsonResponse
    {
        $category = $createCategory->handle($request->toData());

        return (new CategoryResource(self::withDepth($category)))->response()->setStatusCode(201);
    }

    /** `GET /categories/{category}` — archived categories included, needs catalog.view. */
    public function show(Category $category): CategoryResource
    {
        $this->authorize('view', $category);

        return new CategoryResource(self::withDepth($category));
    }

    /** `PATCH /categories/{category}` — needs catalog.update. */
    #[OpenApiResponse(409, 'Archiving with active subcategories, or restoring under an archived parent (`conflict`)')]
    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategory $updateCategory): CategoryResource
    {
        return new CategoryResource(self::withDepth($updateCategory->handle($category, $request->changes())));
    }

    /**
     * `POST /categories/{category}/move` — reparent and/or reorder, needs catalog.update.
     * A 422 for a move under itself or a descendant, or past three levels.
     */
    #[OpenApiResponse(409, 'An active category cannot move under an archived parent (`conflict`)')]
    public function move(MoveCategoryRequest $request, Category $category, MoveCategory $moveCategory): CategoryResource
    {
        return new CategoryResource(self::withDepth($moveCategory->handle($category, $request->parentId(), $request->position())));
    }

    /** `DELETE /categories/{category}` — archives (idempotent), needs catalog.archive. */
    #[OpenApiResponse(409, 'The category still has active subcategories (`conflict`)')]
    public function destroy(Category $category, ArchiveCategory $archiveCategory): Response
    {
        $this->authorize('delete', $category);

        $archiveCategory->handle($category);

        return response()->noContent();
    }

    private static function withDepth(Category $category): Category
    {
        return Category::query()->withTreeDepth()->findOrFail($category->id);
    }
}
