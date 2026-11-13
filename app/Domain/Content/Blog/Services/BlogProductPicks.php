<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Services;

use App\Domain\Catalog\Contracts\AvailableStockQuery;
use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Which linked products a post may show (guide picks and manual related products): only
 * products that are live (active, published, not archived) AND have an active variant with
 * available stock. Anything else is hidden on read — the row stays, so the pick returns when
 * the product is restocked or republished (rule 25c's guardrails, applied to content).
 *
 * Stock comes through Catalog's AvailableStockQuery contract, never the Inventory domain.
 */
final class BlogProductPicks
{
    /** Eager loads a storefront product card needs (ProductListing::RELATIONS). */
    public const array CARD_RELATIONS = ['brand', 'activeVariants', 'images'];

    public function __construct(private readonly AvailableStockQuery $stock) {}

    /**
     * The visible products among `$productIds`, keyed by id, each marked `listing_in_stock = 1`
     * so the card resource needs no further stock query.
     *
     * @param  list<int>  $productIds
     * @return array<int, Product>
     */
    public function visible(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map(intval(...), $productIds)));
        if ($productIds === []) {
            return [];
        }

        $inStock = DB::table('product_variants')
            ->joinSub($this->stock->availableByVariant(), 'variant_stock', 'variant_stock.variant_id', '=', 'product_variants.id')
            ->where('product_variants.status', VariantStatus::Active->value)
            ->where('variant_stock.available', '>', 0)
            ->whereIn('product_variants.product_id', $productIds)
            ->distinct()
            ->pluck('product_variants.product_id')
            ->map(intval(...))
            ->all();

        if ($inStock === []) {
            return [];
        }

        $out = [];
        foreach (Product::query()->published()->whereIn('products.id', $inStock)->with(self::CARD_RELATIONS)->get() as $product) {
            $product->setAttribute('listing_in_stock', 1);
            $out[$product->id] = $product;
        }

        return $out;
    }
}
