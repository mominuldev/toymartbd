<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Actions\GenerateVariantBarcodes;
use App\Domain\Catalog\Models\Product;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Product\BulkGenerateBarcodesRequest;
use App\Http\Requests\Admin\Product\GenerateBarcodesRequest;
use App\Http\Resources\Admin\GeneratedBarcodeResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * In-store barcodes (owner request 2026-11-16): an EAN-13 with the GS1 restricted-circulation
 * prefix for variants that have none. Needs catalog.update. A barcode already set — typed in,
 * imported or generated — is never replaced, so both calls are safe to repeat. Returns
 * `{ data }`: only the variants that got a barcode now (empty when none needed one).
 */
final class ProductBarcodeController extends ApiController
{
    /** `POST /products/{product}/barcodes` — this product's variants (or `variant_ids` of them). */
    #[OpenApiResponse(409, 'A generated code was taken at the same moment, or the prefix is exhausted (`conflict`)')]
    public function store(GenerateBarcodesRequest $request, Product $product, GenerateVariantBarcodes $generate): AnonymousResourceCollection
    {
        return GeneratedBarcodeResource::collection($generate->handle([$product->id], $request->variantIds())->values());
    }

    /** `POST /products/barcodes` — every active variant without a barcode of each of `product_ids`. */
    #[OpenApiResponse(409, 'A generated code was taken at the same moment, or the prefix is exhausted (`conflict`)')]
    public function bulk(BulkGenerateBarcodesRequest $request, GenerateVariantBarcodes $generate): AnonymousResourceCollection
    {
        return GeneratedBarcodeResource::collection($generate->handle($request->productIds())->values());
    }
}
