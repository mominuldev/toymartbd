<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Catalog\Models\Brand;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Storefront\StorefrontBrandResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Storefront brands — active only. Read by the Next.js server with the
 * `storefront:read` token; pages are cached by tag and purged through
 * docs/api/revalidation.md (`brands`, `brand:{slug}`).
 */
final class BrandController extends ApiController
{
    public const int MAX_BRANDS = 500;

    /** `GET /brands` — every active brand, by name, unpaginated (capped at 500). */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    public function index(): AnonymousResourceCollection
    {
        $brands = Brand::query()
            ->active()
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::MAX_BRANDS)
            ->get();

        return StorefrontBrandResource::collection($brands);
    }

    /** `GET /brands/{slug}` — one active brand; 404 when missing or archived. */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'No active brand has this slug')]
    public function show(string $slug): StorefrontBrandResource
    {
        $brand = Brand::query()->active()->where('slug', $slug)->firstOrFail();

        return new StorefrontBrandResource($brand);
    }
}
