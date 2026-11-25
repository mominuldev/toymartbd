<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Recommendations;

use App\Domain\Catalog\Contracts\AvailableStockQuery;
use App\Domain\Catalog\Enums\RelationType;
use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCopurchase;
use App\Domain\Catalog\Models\ProductRelation;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\ProductListing;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The storefront recommendation resolver (PRD §3.1a X-01…X-10, CLAUDE.md rules 25b/25c,
 * docs/api/recommendations.md). Every guardrail lives here, server-side, applied on read:
 *
 * - a recommended product is live (active, `published_at` reached, not deleted), listed in
 *   the online store, and has an active variant with available stock — archived,
 *   unpublished, POS-only and sold-out products are dropped, never shown greyed out;
 * - never the product itself (nor, on the cart and confirmation rails, anything already in
 *   the cart or the order);
 * - an upsell must cost more than the product it upgrades, checked on every read (a price
 *   change after curation must not turn an "upgrade" into a downgrade);
 * - the caps in `catalog.recommendations` are enforced here, not by the client;
 * - on the product page a product appears in at most one block, by priority
 *   upsell > fbt > cross_sell > age_progression;
 * - the cart and confirmation rails are cross-sell only — never an upsell (rule 25b).
 *
 * Stock comes through {@see AvailableStockQuery} so Catalog never depends on Inventory
 * (deptrac). Queries are bounded: the product page costs a fixed handful of queries
 * whatever the number of relations (no N+1). Every returned product is loaded with
 * ProductListing::RELATIONS and carries `listing_in_stock = 1`, so the card resource
 * needs no further stock query.
 */
final class RecommendationResolver
{
    public function __construct(private readonly AvailableStockQuery $stock) {}

    /** Recommendations for the product page. `$product` needs `activeVariants` loaded. */
    public function forProduct(Product $product): ProductRecommendations
    {
        $caps = self::caps();

        /** @var array<string, list<int>> $curated relation type → target ids, best first */
        $curated = [RelationType::Upsell->value => [], RelationType::CrossSell->value => [], RelationType::AgeProgression->value => []];
        ProductRelation::query()
            ->where('product_id', $product->id)
            ->whereIn('type', array_keys($curated))
            ->where('related_product_id', '!=', $product->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['type', 'related_product_id'])
            ->each(static function (ProductRelation $r) use (&$curated): void {
                $curated[$r->type->value][] = $r->related_product_id;
            });

        // Headroom: every block can lose candidates to the dedup by a higher-priority block.
        $headroom = $caps['upsell'] + $caps['fbt_items'] + $caps['cross_sell'];
        $copurchased = $this->copurchasedIds([$product->id], $caps['fbt_items'] + $headroom);
        $nextStage = $this->ageProgressionIds($product, $caps['age_progression'] + $headroom);

        $eligible = $this->eligible([...array_merge(...array_values($curated)), ...$copurchased, ...$nextStage], [$product->id]);
        if ($eligible === []) {
            return ProductRecommendations::none();
        }

        $shown = [$product->id => true];

        $upsell = [];
        $sourcePrice = self::lowestPrice($product);
        foreach ($curated[RelationType::Upsell->value] as $id) {
            $target = $eligible[$id] ?? null;
            $targetPrice = $target === null ? null : self::lowestPrice($target);
            if ($sourcePrice === null || $target === null || $targetPrice === null || isset($shown[$id]) || ! $targetPrice->greaterThan($sourcePrice)) {
                continue;
            }
            $upsell[] = new UpsellRecommendation($target, $targetPrice->minus($sourcePrice));
            $shown[$id] = true;
            if (count($upsell) >= $caps['upsell']) {
                break;
            }
        }

        $fbt = $this->frequentlyBoughtTogether($product, $copurchased, $eligible, $shown, $caps['fbt_items']);
        foreach ($fbt->items ?? [] as $item) {
            $shown[$item->product->id] = true;
        }

        $crossSell = self::pick($curated[RelationType::CrossSell->value], $eligible, $shown, $caps['cross_sell']);
        $ageProgression = self::pick([...$curated[RelationType::AgeProgression->value], ...$nextStage], $eligible, $shown, $caps['age_progression']);

        return new ProductRecommendations($upsell, $crossSell, $ageProgression, $fbt);
    }

    /**
     * The cart rail (PRD X-04, placement `cart_cross_sell`): cross-sell only, never upsell.
     *
     * @param  list<int>  $cartProductIds  the cart's products, in line order
     * @return Collection<int, Product>
     */
    public function forCart(array $cartProductIds): Collection
    {
        return $this->crossSellFor($cartProductIds, self::caps()['cart_cross_sell']);
    }

    /**
     * The order-confirmation rail (PRD X-06, placement `confirmation_cross_sell`).
     *
     * @param  list<int>  $orderProductIds  the order's products, in line order
     * @return Collection<int, Product>
     */
    public function forOrder(array $orderProductIds): Collection
    {
        return $this->crossSellFor($orderProductIds, self::caps()['confirmation_cross_sell']);
    }

    /**
     * Curated `cross_sell` pairs of the given products (round-robin by position, so every line
     * gets its first pick before any gets its second), then co-purchase fill; never one of the
     * given products, never an upsell.
     *
     * @param  list<int>  $sourceIds
     * @return Collection<int, Product>
     */
    private function crossSellFor(array $sourceIds, int $cap): Collection
    {
        $sourceIds = array_values(array_unique(array_map('intval', $sourceIds)));
        if ($sourceIds === [] || $cap <= 0) {
            return new Collection;
        }

        $order = array_flip($sourceIds);
        $curated = ProductRelation::query()
            ->whereIn('product_id', $sourceIds)
            ->where('type', RelationType::CrossSell->value)
            ->whereNotIn('related_product_id', $sourceIds)
            ->get(['id', 'product_id', 'related_product_id', 'position'])
            ->sortBy(static fn (ProductRelation $r): array => [$r->position, $order[$r->product_id] ?? PHP_INT_MAX, $r->id])
            ->map(static fn (ProductRelation $r): int => $r->related_product_id)
            ->values()
            ->all();

        $candidates = [...$curated, ...$this->copurchasedIds($sourceIds, $cap * 2)];
        $shown = array_fill_keys($sourceIds, true);

        return self::pick($candidates, $this->eligible($candidates, $sourceIds), $shown, $cap);
    }

    /**
     * The frequently-bought-together block, or null when it cannot be shown whole: the source
     * needs an in-stock purchasable variant, and at least one co-purchased product must have
     * one too. Products already shown (upsells) are skipped. One stock query.
     *
     * @param  list<int>  $copurchased
     * @param  array<int, Product>  $eligible
     * @param  array<int, true>  $shown
     */
    private function frequentlyBoughtTogether(Product $product, array $copurchased, array $eligible, array $shown, int $cap): ?FrequentlyBoughtTogether
    {
        $sourceVariant = self::purchasableVariant($product);
        if ($sourceVariant === null || $cap <= 0) {
            return null;
        }

        /** @var list<FrequentlyBoughtTogetherItem> $candidates */
        $candidates = [];
        foreach (array_unique($copurchased) as $id) {
            $target = $eligible[$id] ?? null;
            $variant = $target === null || isset($shown[$id]) ? null : self::purchasableVariant($target);
            if ($target !== null && $variant !== null) {
                $candidates[] = new FrequentlyBoughtTogetherItem($target, $variant);
            }
        }
        if ($candidates === []) {
            return null;
        }

        $inStock = $this->inStockVariantIds([$sourceVariant->id, ...array_map(static fn (FrequentlyBoughtTogetherItem $i): int => $i->variant->id, $candidates)]);
        if (! isset($inStock[$sourceVariant->id])) {
            return null;
        }

        // The source's card is in stock by construction too (its purchasable variant is).
        $product->setAttribute('listing_in_stock', 1);
        $items = [new FrequentlyBoughtTogetherItem($product, $sourceVariant)];
        foreach ($candidates as $candidate) {
            if (isset($inStock[$candidate->variant->id])) {
                $items[] = $candidate;
            }
            if (count($items) > $cap) {
                break;
            }
        }

        return count($items) < 2 ? null : new FrequentlyBoughtTogether($items);
    }

    /**
     * Products bought with any of `$sourceIds` in at least `catalog.fbt_min_orders` orders,
     * strongest signal first. Reads the `product_copurchases` projection (both directions stored).
     *
     * @param  list<int>  $sourceIds
     * @return list<int>
     */
    private function copurchasedIds(array $sourceIds, int $limit): array
    {
        if ($sourceIds === [] || $limit <= 0) {
            return [];
        }

        return ProductCopurchase::query()
            ->whereIn('product_id', $sourceIds)
            ->whereNotIn('other_product_id', $sourceIds)
            ->where('orders_count', '>=', max(1, (int) config('catalog.fbt_min_orders', 3)))
            ->groupBy('other_product_id')
            ->orderByRaw('SUM(orders_count) DESC')
            ->orderByRaw('MAX(last_ordered_at) DESC')
            ->orderBy('other_product_id')
            ->limit($limit)
            ->pluck('other_product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * "Next stage" candidates (PRD X-07): eligible products of the same category — the source's
     * primary category is their primary category or one they are filed under — whose youngest
     * age is at least the source's oldest, nearest band first. Empty when the source has no
     * upper age.
     *
     * @return list<int>
     */
    private function ageProgressionIds(Product $product, int $limit): array
    {
        if ($product->age_max_months === null || $limit <= 0) {
            return [];
        }

        $categoryId = $product->primary_category_id;

        return $this->listable()
            ->where('products.id', '!=', $product->id)
            ->whereNotNull('products.age_min_months')
            ->where('products.age_min_months', '>=', $product->age_max_months)
            ->where(static function (Builder $q) use ($categoryId): void {
                $q->where('products.primary_category_id', $categoryId)
                    ->orWhereIn('products.id', DB::table('category_product')->select('product_id')->where('category_id', $categoryId));
            })
            ->orderBy('products.age_min_months')
            ->orderByDesc('products.is_featured')
            ->orderByDesc('products.published_at')
            ->orderByDesc('products.id')
            ->limit($limit)
            ->pluck('products.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * The eligible products among `$ids` (live, online, in stock, not excluded), loaded for the
     * product card, keyed by id. One query plus the card's eager loads.
     *
     * @param  list<int>  $ids
     * @param  list<int>  $exclude
     * @return array<int, Product>
     */
    private function eligible(array $ids, array $exclude): array
    {
        $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), $exclude));
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach ($this->listable()->whereIn('products.id', $ids)->with(ProductListing::RELATIONS)->get() as $product) {
            // In stock by construction: the card resource reads this instead of querying stock again.
            $product->setAttribute('listing_in_stock', 1);
            $out[$product->id] = $product;
        }

        return $out;
    }

    /**
     * Live, listed in the online store, with an active variant that has available stock.
     *
     * @return Builder<Product>
     */
    private function listable(): Builder
    {
        $stock = $this->stock;

        return Product::query()
            ->published()
            ->where('products.channel_online_store', true)
            ->whereExists(static function (QueryBuilder $q) use ($stock): void {
                $q->selectRaw('1')
                    ->from('product_variants')
                    ->joinSub($stock->availableByVariant(), 'reco_stock', 'reco_stock.variant_id', '=', 'product_variants.id')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('product_variants.status', VariantStatus::Active->value)
                    ->where('reco_stock.available', '>', 0);
            });
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, true>
     */
    private function inStockVariantIds(array $variantIds): array
    {
        $ids = DB::query()
            ->fromSub($this->stock->availableByVariant(), 'reco_stock')
            ->whereIn('variant_id', array_values(array_unique($variantIds)))
            ->where('available', '>', 0)
            ->pluck('variant_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_fill_keys($ids, true);
    }

    /**
     * The first `$cap` eligible, not-yet-shown products of `$ids`, in order; marks them shown.
     *
     * @param  list<int>  $ids
     * @param  array<int, Product>  $eligible
     * @param  array<int, true>  $shown
     * @return Collection<int, Product>
     */
    private static function pick(array $ids, array $eligible, array &$shown, int $cap): Collection
    {
        $picked = new Collection;
        foreach ($ids as $id) {
            if ($picked->count() >= $cap) {
                break;
            }
            if (isset($eligible[$id]) && ! isset($shown[$id])) {
                $picked->push($eligible[$id]);
                $shown[$id] = true;
            }
        }

        return $picked;
    }

    /** The one active variant, or the active default of several; null when neither exists. */
    private static function purchasableVariant(Product $product): ?ProductVariant
    {
        $variants = $product->activeVariants;

        return $variants->count() === 1 ? $variants->first() : $variants->first(static fn (ProductVariant $v): bool => $v->is_default);
    }

    /** The lowest active variant price — the card's `price`. */
    private static function lowestPrice(Product $product): ?Money
    {
        $lowest = null;
        foreach ($product->activeVariants as $variant) {
            if ($lowest === null || $variant->price->lessThan($lowest)) {
                $lowest = $variant->price;
            }
        }

        return $lowest;
    }

    /** @return array{upsell: int, cross_sell: int, age_progression: int, fbt_items: int, cart_cross_sell: int, confirmation_cross_sell: int} */
    private static function caps(): array
    {
        $config = (array) config('catalog.recommendations', []);
        $defaults = ['upsell' => 2, 'cross_sell' => 4, 'age_progression' => 4, 'fbt_items' => 2, 'cart_cross_sell' => 8, 'confirmation_cross_sell' => 4];

        $caps = [];
        foreach ($defaults as $key => $default) {
            $caps[$key] = max(0, (int) ($config[$key] ?? $default));
        }

        return $caps;
    }
}
