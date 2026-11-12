<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Actions\ArchiveProduct;
use App\Domain\Catalog\Actions\DuplicateProduct;
use App\Domain\Catalog\Actions\UpdateProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\CreateProductWithOpeningStock;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Seo\Actions\SaveProductWithSeo;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Product\DuplicateProductRequest;
use App\Http\Requests\Admin\Product\ProductListFilters;
use App\Http\Requests\Admin\Product\StoreProductRequest;
use App\Http\Requests\Admin\Product\UpdateProductRequest;
use App\Http\Resources\Admin\ProductListItemResource;
use App\Http\Resources\Admin\ProductResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Products (P4-CAT-01..03). One document per product: `PUT` replaces fields,
 * categories, the variant matrix and curated relations in one call. Archive, never
 * delete: `DELETE` sets the product archived; its variants and SKUs stay.
 */
final class ProductController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /products` — cursor-paginated, needs catalog.view.
     *
     * Filters: `filter[search]` (name, name_bn, slug or a variant SKU contains),
     * `filter[status]` = `draft|active|archived`, `filter[type]` = `simple|variable`,
     * `filter[brand_id]`, `filter[category_id]` (any of the product's categories).
     * Sorts: `name`, `created_at`, `updated_at`, each with `-` for descending
     * (default `-updated_at`). Anything else is a 400.
     */
    #[QueryParameter('filter[search]', 'Case-insensitive "contains" match on name, name_bn, slug or a variant SKU.', type: 'string')]
    #[QueryParameter('filter[status]', 'Only products in this status.', type: "'draft'|'active'|'archived'")]
    #[QueryParameter('filter[type]', 'Only simple or only variable products.', type: "'simple'|'variable'")]
    #[QueryParameter('filter[brand_id]', 'Only products of this brand.', type: 'int')]
    #[QueryParameter('filter[category_id]', 'Only products in this category (primary or not).', type: 'int')]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'name'|'-name'|'created_at'|'-created_at'|'updated_at'|'-updated_at'", default: '-updated_at')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $products = ProductListFilters::query($request)
            ->with(ProductListItemResource::RELATIONS)
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return ProductListItemResource::collection($products);
    }

    /**
     * `POST /products` — needs catalog.create (and catalog.view_cost to send `cost_price`).
     * `variants[i].opening_stock` records each variant's opening on-hand as an
     * `opening_balance` stock movement in the same transaction; above zero it needs
     * inventory.adjust.
     */
    #[OpenApiResponse(403, 'Missing catalog.create, `cost_price` sent without catalog.view_cost, or `opening_stock` without inventory.adjust (`forbidden`)')]
    public function store(StoreProductRequest $request, CreateProductWithOpeningStock $createProduct, SaveProductWithSeo $save): JsonResponse
    {
        if ($request->setsOpeningStock()) {
            $this->authorize('adjust', StockLevel::class);
        }

        $context = $request->writeContext();
        $product = $save->handle(
            null,
            fn (): Product => $createProduct->handle($request->toData(), $context, $request->openingStock()),
            $request->seoData(),
            $context->actorId,
        );

        return (new ProductResource($product->load(ProductResource::RELATIONS)))->response()->setStatusCode(201);
    }

    /** `GET /products/{product}` — with variants (archived included), images, categories and relations; needs catalog.view. */
    public function show(Product $product): ProductResource
    {
        $this->authorize('view', $product);

        return new ProductResource($product->load(ProductResource::RELATIONS));
    }

    /**
     * `PUT /products/{product}` — replaces the whole document; needs catalog.update.
     * Changing a selling price or adding a variant also needs catalog.update_price;
     * sending `cost_price` needs catalog.view_cost. Changing a live product's slug needs
     * `acknowledge_slug_redirect: true` and records the old slug in `slug_history`.
     */
    #[OpenApiResponse(403, 'Missing catalog.update, a price change without catalog.update_price, or `cost_price` without catalog.view_cost (`forbidden`)')]
    public function update(UpdateProductRequest $request, Product $product, UpdateProduct $updateProduct, SaveProductWithSeo $save): ProductResource
    {
        $context = $request->writeContext();
        $product = $save->handle(
            $product,
            fn (): Product => $updateProduct->handle($product, $request->toData(), $context),
            $request->seoData(),
            $context->actorId,
        );

        return new ProductResource($product->load(ProductResource::RELATIONS));
    }

    /**
     * `POST /products/{product}/duplicate` — a draft copy with a new slug and new SKUs
     * (barcodes are not copied); needs catalog.create.
     */
    public function duplicate(DuplicateProductRequest $request, Product $product, DuplicateProduct $duplicateProduct): JsonResponse
    {
        $copy = $duplicateProduct->handle($product, $request->user()?->id);

        return (new ProductResource($copy->load(ProductResource::RELATIONS)))->response()->setStatusCode(201);
    }

    /** `DELETE /products/{product}` — archives the product (idempotent), needs catalog.archive. */
    public function destroy(Product $product, ArchiveProduct $archiveProduct): Response
    {
        $this->authorize('delete', $product);

        $archiveProduct->handle($product);

        return response()->noContent();
    }
}
