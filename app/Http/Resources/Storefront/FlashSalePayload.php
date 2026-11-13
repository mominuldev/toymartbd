<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Marketing\Services\FlashSaleLookup;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The `flash_sale` object on storefront cards and product pages (P7). Every figure is
 * computed here (rule 1); `ends_at` is server time, ISO 8601 UTC — the countdown only
 * counts down to it. Null when no active variant is on a running flash sale with units left.
 *
 * @internal
 */
final class FlashSalePayload
{
    /**
     * The cheapest flash offer among these variants.
     *
     * @param  Collection<int, ProductVariant>|iterable<ProductVariant>  $variants
     * @return array{promotion_id: int, name: string, name_bn: string|null, ends_at: string, variant_id: int, sale_price: string, regular_price: string, discount_percent: int, remaining: int}|null
     */
    public static function forVariants(iterable $variants): ?array
    {
        $offers = app(FlashSaleLookup::class)->byVariant();
        $best = null;
        foreach ($variants as $variant) {
            $offer = $offers[$variant->id] ?? null;
            if ($offer === null || ! $offer['sale_price']->lessThan($variant->price)) {
                continue;
            }
            if ($best === null || $offer['sale_price']->lessThan($best[1]['sale_price'])) {
                $best = [$variant, $offer];
            }
        }
        if ($best === null) {
            return null;
        }
        [$variant, $offer] = $best;

        return [
            'promotion_id' => $offer['promotion_id'],
            'name' => $offer['name'],
            'name_bn' => $offer['name_bn'],
            'ends_at' => $offer['ends_at'],
            'variant_id' => $variant->id,
            'sale_price' => $offer['sale_price']->toDecimal(),
            'regular_price' => $variant->price->toDecimal(),
            'discount_percent' => self::percent($offer['sale_price'], $variant->price),
            'remaining' => $offer['remaining'],
        ];
    }

    /**
     * Per variant (product page): variant id → its own flash offer.
     *
     * @param  iterable<ProductVariant>  $variants
     * @return array<int, array{sale_price: string, discount_percent: int, remaining: int}>
     */
    public static function perVariant(iterable $variants): array
    {
        $offers = app(FlashSaleLookup::class)->byVariant();
        $out = [];
        foreach ($variants as $variant) {
            $offer = $offers[$variant->id] ?? null;
            if ($offer !== null && $offer['sale_price']->lessThan($variant->price)) {
                $out[$variant->id] = [
                    'sale_price' => $offer['sale_price']->toDecimal(),
                    'discount_percent' => self::percent($offer['sale_price'], $variant->price),
                    'remaining' => $offer['remaining'],
                ];
            }
        }

        return $out;
    }

    /** Whole percent off, rounded down — never overstates a saving. */
    private static function percent(Money $sale, Money $regular): int
    {
        return (int) bcdiv(bcmul(bcsub($regular->amount, $sale->amount, 2), '100', 2), $regular->amount, 0);
    }
}
