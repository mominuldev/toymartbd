<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Actions\ArchiveBrand;
use App\Domain\Catalog\Actions\CreateBrand;
use App\Domain\Catalog\Actions\UpdateBrand;
use App\Domain\Catalog\Enums\BrandStatus;
use App\Domain\Catalog\Models\Brand;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Brand\StoreBrandRequest;
use App\Http\Requests\Admin\Brand\UpdateBrandRequest;
use App\Http\Resources\Admin\BrandResource;
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
 * Brands (P3-ADM-04). Archive, never delete: `DELETE` sets the brand archived,
 * which hides it from the storefront and from `filter[status]=active`.
 */
final class BrandController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /brands` — cursor-paginated, needs catalog.view.
     *
     * Filters: `filter[search]` (name, name_bn or slug contains), `filter[status]` = `active|archived`.
     * Sorts: `name`, `-name`, `created_at`, `-created_at` (default `name`). Anything else is a 400.
     */
    #[QueryParameter('filter[search]', 'Case-insensitive "contains" match on name, name_bn or slug.', type: 'string')]
    #[QueryParameter('filter[status]', 'Only active or only archived brands.', type: "'active'|'archived'")]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'name'|'-name'|'created_at'|'-created_at'", default: 'name')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Brand::class);

        $sort = $request->query('sort');
        $descending = is_string($sort) && str_starts_with($sort, '-');

        $brands = QueryBuilder::for(Brand::class, $request)
            ->allowedFilters(
                AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                    $term = is_array($value) ? implode(',', array_map(strval(...), $value)) : (string) $value;
                    $like = '%'.addcslashes($term, '\\%_').'%';

                    $query->where(static function (Builder $q) use ($like): void {
                        $q->where('name', 'like', $like)
                            ->orWhere('name_bn', 'like', $like)
                            ->orWhere('slug', 'like', $like);
                    });
                }),
                AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                    $status = is_string($value) ? BrandStatus::tryFrom($value) : null;

                    if ($status === null) {
                        throw new BadRequestHttpException('filter[status] must be one of: active, archived.');
                    }

                    $query->where('status', $status->value);
                }),
            )
            ->allowedSorts('name', 'created_at')
            ->defaultSort('name')
            // A unique tiebreaker keeps cursor pagination stable.
            ->orderBy('id', $descending ? 'desc' : 'asc')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return BrandResource::collection($brands);
    }

    /** `POST /brands` — needs catalog.create. */
    public function store(StoreBrandRequest $request, CreateBrand $createBrand): JsonResponse
    {
        $brand = $createBrand->handle($request->toData());

        return (new BrandResource($brand))->response()->setStatusCode(201);
    }

    /** `GET /brands/{brand}` — archived brands included, needs catalog.view. */
    public function show(Brand $brand): BrandResource
    {
        $this->authorize('view', $brand);

        return new BrandResource($brand);
    }

    /** `PATCH /brands/{brand}` — needs catalog.update. */
    public function update(UpdateBrandRequest $request, Brand $brand, UpdateBrand $updateBrand): BrandResource
    {
        return new BrandResource($updateBrand->handle($brand, $request->changes()));
    }

    /** `DELETE /brands/{brand}` — archives the brand (idempotent), needs catalog.archive. */
    public function destroy(Brand $brand, ArchiveBrand $archiveBrand): Response
    {
        $this->authorize('delete', $brand);

        $archiveBrand->handle($brand);

        return response()->noContent();
    }
}
