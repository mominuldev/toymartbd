<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductListing;
use App\Domain\Marketing\Models\FlashSaleItem;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Services\FlashSaleLookup;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Storefront\StorefrontProductCardResource;
use Illuminate\Http\JsonResponse;

/**
 * The `/flash-sale` page and home rail (P7). Running flash sales with their products
 * (published, as cards — each carrying its `flash_sale` offer), plus the next scheduled
 * sale. The storefront caches it under the `flash-sale` tag, revalidated on every save
 * and at each start/end boundary.
 */
final class FlashSaleController extends ApiController
{
    public const int MAX_PRODUCTS = 60;

    /**
     * `GET /flash-sales`.
     *
     * `server_time` is the API's clock (UTC) when the page was rendered; times are ISO 8601 UTC.
     */
    public function index(FlashSaleLookup $lookup): JsonResponse
    {
        $now = now();
        $sales = [];
        foreach ($lookup->running($now) as $promotion) {
            $sales[] = self::sale($promotion);
        }
        $next = $lookup->next($now);

        return response()->json([
            'server_time' => $now->utc()->format('Y-m-d\TH:i:s\Z'),
            'sales' => $sales,
            'next' => $next === null ? null : [
                'id' => $next->id,
                'name' => $next->name,
                'name_bn' => $next->name_bn,
                'starts_at' => $next->starts_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
        ]);
    }

    /** @return array{id: int, name: string, name_bn: string|null, starts_at: string|null, ends_at: string, products: list<mixed>} */
    private static function sale(Promotion $promotion): array
    {
        $variantIds = $promotion->flashItems->map(static fn (FlashSaleItem $i): int => $i->variant_id)->all();
        $productIds = \App\Domain\Catalog\Models\ProductVariant::query()->whereIn('id', $variantIds)->distinct()->pluck('product_id')->all();
        $products = Product::query()->published()->whereIn('id', $productIds)->with(ProductListing::RELATIONS)
            ->orderBy('id')->limit(self::MAX_PRODUCTS)->get();
        StorefrontProductCardResource::primeStock($products); // one stock query for every card (Phase 9 P-10)

        return [
            'id' => $promotion->id,
            'name' => $promotion->name,
            'name_bn' => $promotion->name_bn,
            'starts_at' => $promotion->starts_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => (string) $promotion->ends_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'products' => StorefrontProductCardResource::collection($products)->resolve(),
        ];
    }
}
