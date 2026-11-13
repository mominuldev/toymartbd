<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Ordering\Queries\OrderComposerLookups;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Promotion\MarketingNameLookupRequest;
use App\Http\Requests\Admin\Promotion\MarketingVariantLookupRequest;
use App\Http\Resources\Admin\OrderComposerVariantResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Lookups for the coupon and promotion builders (P7 follow-up), under marketing permissions. */
final class MarketingLookupController extends ApiController
{
    /**
     * `GET /marketing/lookups/variants?q=` — sellable variants by name (EN/BN), SKU or barcode,
     * with their price and the stock available now (the same search the order composer uses).
     */
    public function variants(MarketingVariantLookupRequest $request, OrderComposerLookups $lookups): AnonymousResourceCollection
    {
        return OrderComposerVariantResource::collection($lookups->variants($request->term()));
    }

    /**
     * `GET /marketing/lookups/names?kind=&ids=` — id → name for scope chips. Archived rows are
     * included (a saved scope may still name them); unknown ids are left out.
     *
     * @response array{data: list<array{id: int, name: string, name_bn: string|null}>}
     */
    public function names(MarketingNameLookupRequest $request): JsonResponse
    {
        $query = match ($request->kind()) {
            'products' => Product::withTrashed(),
            'categories' => Category::withTrashed(),
            default => Brand::query(),
        };

        $rows = $query->whereIn('id', $request->ids())->orderBy('id')->get(['id', 'name', 'name_bn']);

        return response()->json(['data' => $rows->map(static fn ($row): array => [
            'id' => (int) $row->getKey(),
            'name' => (string) $row->getAttribute('name'),
            'name_bn' => $row->getAttribute('name_bn'),
        ])->values()->all()]);
    }
}
