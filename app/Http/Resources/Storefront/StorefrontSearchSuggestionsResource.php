<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Catalog\Models\Product;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Header autocomplete groups (Figma SearchSuggestions 115:887): products → categories →
 * brands, and the total hit count for the footer. `count` on a category or brand is the
 * number of matching products in it; null in degraded mode (Meilisearch unavailable).
 *
 * @property array{
 *     query: string,
 *     products: Collection<int, Product>,
 *     categories: list<array{slug: string, name: string, name_bn: string|null, icon: string|null, count: int|null}>,
 *     brands: list<array{slug: string, name: string, name_bn: string|null, count: int|null}>,
 *     total: int,
 *     degraded: bool,
 * } $resource
 */
#[SchemaName('StorefrontSearchSuggestions')]
final class StorefrontSearchSuggestionsResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'query' => $this->resource['query'],
            /** @var list<StorefrontProductCardResource> */
            'products' => StorefrontProductCardResource::collection($this->resource['products']),
            /** @var list<array{slug: string, name: string, name_bn: string|null, icon: string|null, count: int|null}> */
            'categories' => $this->resource['categories'],
            /** @var list<array{slug: string, name: string, name_bn: string|null, count: int|null}> */
            'brands' => $this->resource['brands'],
            'total' => $this->resource['total'],
            'degraded' => $this->resource['degraded'],
        ];
    }
}
