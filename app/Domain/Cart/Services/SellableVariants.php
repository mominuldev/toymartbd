<?php

declare(strict_types=1);

namespace App\Domain\Cart\Services;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\ProductVariant;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * What can be sold right now: an active variant of an active, published, not-deleted
 * product. Loads what pricing and the cart response need (product brand, categories,
 * names, slug) in one query.
 */
final class SellableVariants
{
    /**
     * @param  array<int, int>  $lines  variant id → quantity (non-positive quantities ignored)
     * @return array{list<ProductVariant>, list<int>} sellable variants in request order, and the ids that cannot be sold
     */
    public function split(array $lines, ?DateTimeInterface $now = null): array
    {
        $now ??= now();
        $lines = array_filter($lines, static fn (int $qty): bool => $qty > 0);
        if ($lines === []) {
            return [[], []];
        }

        $found = ProductVariant::query()
            ->with([
                'product' => fn ($q) => $q->withTrashed()->select(['id', 'brand_id', 'status', 'published_at', 'deleted_at', 'name', 'name_bn', 'slug']),
                'product.categories' => fn (BelongsToMany $q) => $q->select('categories.id'),
            ])
            ->whereIn('id', array_keys($lines))
            ->get()
            ->keyBy('id');

        $sellable = [];
        $unavailable = [];
        foreach (array_keys($lines) as $variantId) {
            $variant = $found->get($variantId);
            if ($variant instanceof ProductVariant && $this->isSellable($variant, $now)) {
                $sellable[] = $variant;
            } else {
                $unavailable[] = (int) $variantId;
            }
        }

        return [$sellable, $unavailable];
    }

    public function isSellable(ProductVariant $variant, ?DateTimeInterface $now = null): bool
    {
        $now ??= now();
        $product = $variant->product;

        return $variant->isActive()
            && $product->status === ProductStatus::Active
            && ! $product->trashed()
            && $product->published_at !== null
            && ! $product->published_at->greaterThan($now);
    }
}
