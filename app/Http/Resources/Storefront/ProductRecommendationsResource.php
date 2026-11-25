<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Catalog\Recommendations\FrequentlyBoughtTogether;
use App\Domain\Catalog\Recommendations\FrequentlyBoughtTogetherItem;
use App\Domain\Catalog\Recommendations\ProductRecommendations;
use App\Domain\Catalog\Recommendations\UpsellRecommendation;
use App\Domain\Marketing\Services\FlashSaleLookup;
use App\Http\Resources\ApiResource;
use App\Support\Money;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The product page's `recommendations` (PRD §3.1a, docs/api/recommendations.md), already
 * through every guardrail in RecommendationResolver. Every card is a StorefrontProductCard;
 * every money figure is computed here (rule 1): `price_delta` on an upsell and
 * `bundle_total` (plus each item's `unit_price`) on the frequently-bought-together block.
 *
 * @property ProductRecommendations $resource
 */
#[SchemaName('StorefrontProductRecommendations')]
final class ProductRecommendationsResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $recommendations = $this->resource;

        return [
            /**
             * "Upgrade to" (≤ 2): a dearer alternative with `price_delta` = its `price` minus this
             * product's lowest price, always > 0.
             *
             * @var list<array<string, mixed>>
             */
            'upsell' => array_map(static fn (UpsellRecommendation $u): array => [
                ...StorefrontProductCardResource::make($u->product)->resolve($request),
                'price_delta' => $u->priceDelta->toDecimal(),
            ], $recommendations->upsell),
            /**
             * "Goes well with" (≤ 4).
             *
             * @var list<StorefrontProductCardResource>
             */
            'cross_sell' => StorefrontProductCardResource::collection($recommendations->crossSell),
            /**
             * "When they're ready for the next stage" (≤ 4): curated pairs first, then the same
             * category from this product's upper age up, nearest band first.
             *
             * @var list<StorefrontProductCardResource>
             */
            'age_progression' => StorefrontProductCardResource::collection($recommendations->ageProgression),
            /**
             * Frequently bought together: `items[0]` is this product, then 1–2 products bought with
             * it. Each item names the `variant_id` "Add all to cart" posts and its `unit_price`;
             * `bundle_total` is the sum of those unit prices. Null when the block cannot be shown whole.
             *
             * @var array{items: list<array<string, mixed>>, bundle_total: string}|null
             */
            'fbt' => $recommendations->fbt === null ? null : self::fbt($recommendations->fbt, $request),
        ];
    }

    /** @return array{items: list<array<string, mixed>>, bundle_total: string} */
    private static function fbt(FrequentlyBoughtTogether $fbt, Request $request): array
    {
        $offers = app(FlashSaleLookup::class)->byVariant();
        $total = Money::zero();
        $items = [];

        foreach ($fbt->items as $item) {
            $unit = self::unitPrice($item, $offers[$item->variant->id]['sale_price'] ?? null);
            $total = $total->plus($unit);
            $items[] = [
                ...StorefrontProductCardResource::make($item->product)->resolve($request),
                'variant_id' => $item->variant->id,
                'unit_price' => $unit->toDecimal(),
            ];
        }

        return ['items' => $items, 'bundle_total' => $total->toDecimal()];
    }

    /** The variant's current unit price: its running flash-sale price when lower, else its price. */
    private static function unitPrice(FrequentlyBoughtTogetherItem $item, ?Money $flash): Money
    {
        return $flash !== null && $flash->lessThan($item->variant->price) ? $flash : $item->variant->price;
    }
}
