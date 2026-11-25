<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Catalog\Enums\RecommendationPlacement;
use App\Domain\Catalog\Models\Product;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * A recommendation rail outside the product page (P10, docs/api/recommendations.md): the cart's
 * cross-sell rail and the order-confirmation rail. `placement` is what the storefront sends back
 * as `recommendation_source` on add-to-cart and on the recommendation events (rule 25d).
 *
 * @property Collection<int, Product> $resource
 */
#[SchemaName('StorefrontRecommendationRail')]
final class RecommendationRailResource extends ApiResource
{
    /** @param  Collection<int, Product>  $products  in-stock products, best first */
    public function __construct(Collection $products, private readonly RecommendationPlacement $placement)
    {
        parent::__construct($products);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        StorefrontProductCardResource::primeStock($this->resource); // a no-op: the resolver marks every card in stock

        return [
            /** @var list<StorefrontProductCardResource> */
            'data' => StorefrontProductCardResource::collection($this->resource),
            /** @var 'cart_cross_sell'|'confirmation_cross_sell' */
            'placement' => $this->placement->value,
        ];
    }
}
