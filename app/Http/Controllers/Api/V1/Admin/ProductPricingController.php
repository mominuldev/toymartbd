<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Services\Margins;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Product\MarginPreviewRequest;
use Illuminate\Http\JsonResponse;

/**
 * Pricing helpers for the product form. The saved margins come on the product itself
 * (`Product.margin`, `ProductVariant.margin`); this previews unsaved edits.
 */
final class ProductPricingController extends ApiController
{
    /**
     * `POST /products/margin-preview` — per-unit margin for each `rows[i]` (price and
     * cost as decimal strings) at `vat_rate`; null where the price or cost is missing.
     * Needs catalog.view_cost.
     *
     * @response array{rows: list<array{percent: string, profit: string, net_price: string}|null>}
     */
    public function marginPreview(MarginPreviewRequest $request, Margins $margins): JsonResponse
    {
        $vat = $request->vatRate();

        return response()->json([
            'rows' => array_map(
                static fn (array $row): ?array => $margins->forUnit($row['price'], $row['cost'], $vat)?->toArray(),
                $request->rows(),
            ),
        ]);
    }
}
