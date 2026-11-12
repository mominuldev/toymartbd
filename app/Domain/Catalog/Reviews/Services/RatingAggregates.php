<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Reviews\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reviews\Enums\ReviewStatus;
use App\Domain\Catalog\Reviews\Models\Review;

/**
 * `products.rating_avg` / `rating_count` — a projection of the product's **approved**
 * reviews (P4-CAT-12), recomputed from scratch on every moderation change so it can
 * never drift. Called inside the moderating Action's transaction with the product
 * row locked; `rating_avg` is NULL when there is no approved review (never 0, which
 * would read as a one-star product).
 */
final class RatingAggregates
{
    public function recompute(int $productId): void
    {
        $product = Product::withTrashed()->lockForUpdate()->find($productId);

        if ($product === null) {
            return;
        }

        $row = Review::query()
            ->where('product_id', $productId)
            ->where('status', ReviewStatus::Approved->value)
            ->selectRaw('COUNT(*) as n, SUM(rating) as total')
            ->toBase()
            ->first();

        $count = (int) ($row->n ?? 0);
        $total = (int) ($row->total ?? 0);

        $product->rating_count = $count;
        $product->rating_avg = $count === 0 ? null : self::average($total, $count);

        // A moderation change is not a product edit: updated_at stays put. Model events still
        // fire, so the search document picks up the new rating.
        Product::withoutTimestamps(static fn (): bool => $product->save());
    }

    /** total / count, rounded half-up to 2 places in integer arithmetic ("4.67"). */
    public static function average(int $total, int $count): string
    {
        $hundredths = intdiv($total * 200 + $count, $count * 2);

        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    /**
     * Approved review counts by star, 5 → 1, for the rating summary.
     *
     * @return array<int, int>
     */
    public function distribution(int $productId): array
    {
        $counts = Review::query()
            ->where('product_id', $productId)
            ->where('status', ReviewStatus::Approved->value)
            ->selectRaw('rating, COUNT(*) as n')
            ->groupBy('rating')
            ->pluck('n', 'rating');

        $distribution = [];
        foreach ([5, 4, 3, 2, 1] as $stars) {
            $distribution[$stars] = (int) ($counts[$stars] ?? 0);
        }

        return $distribution;
    }
}
