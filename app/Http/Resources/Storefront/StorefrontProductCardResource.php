<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Services\StockAvailability;
use App\Http\Resources\ApiResource;
use App\Support\Money;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A product card on a storefront listing. Every money value is a decimal string
 * computed here — the storefront formats it and never does arithmetic (rule 1):
 * `price` is the lowest active variant price, `compare_at_price` that same
 * variant's was-price (null when none or not higher), `price_max` the highest
 * active variant price (show "from" when it differs from `price`).
 *
 * Needs ProductListing::RELATIONS loaded. `in_stock` comes from the listing's
 * `listing_in_stock` column when the product was read through ProductListing, else
 * from StockAvailability (one query — related-product rails, search hits).
 *
 * @property Product $resource
 */
#[SchemaName('StorefrontProductCard')]
final class StorefrontProductCardResource extends ApiResource
{
    /** Width of the `url` rendition: a 302px desktop card at 1.5×. */
    public const int CARD_WIDTH = 480;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        $variants = $product->activeVariants;

        /** @var ProductVariant|null $cheapest */
        $cheapest = null;
        /** @var ProductVariant|null $dearest */
        $dearest = null;
        foreach ($variants as $variant) {
            if ($cheapest === null || $variant->price->lessThan($cheapest->price)) {
                $cheapest = $variant;
            }
            if ($dearest === null || $variant->price->greaterThan($dearest->price)) {
                $dearest = $variant;
            }
        }

        $compareAt = $cheapest?->compare_at_price;
        if ($compareAt !== null && ! $compareAt->greaterThan($cheapest->price)) {
            $compareAt = null;
        }

        $image = $product->images->first(static fn (ProductImage $i): bool => $i->is_primary) ?? $product->images->first();
        $brand = $product->brand;

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'name' => $product->name,
            /** @var string|null */
            'name_bn' => $product->name_bn,
            /** @var array{name: string, name_bn: string|null, slug: string}|null */
            'brand' => $brand === null ? null : ['name' => $brand->name, 'name_bn' => $brand->name_bn, 'slug' => $brand->slug],
            /** @var string */
            'price' => $cheapest?->price->toDecimal() ?? '0.00',
            /** @var string|null */
            'compare_at_price' => $compareAt?->toDecimal(),
            /** @var string */
            'price_max' => $dearest?->price->toDecimal() ?? '0.00',
            /** @var int|null */
            'discount_percent' => $compareAt === null ? null : self::discountPercent($cheapest->price, $compareAt),
            /** @var int|null */
            'age_min_months' => $product->age_min_months,
            /** @var int|null */
            'age_max_months' => $product->age_max_months,
            /** @var string|null */
            'rating_avg' => $product->rating_avg === null ? null : number_format((float) $product->rating_avg, 2, '.', ''),
            'rating_count' => $product->rating_count,
            'is_featured' => $product->is_featured,
            /** @var int */
            'variant_count' => $variants->count(),
            /**
             * An active variant has available stock (on_hand − reserved > 0). Sold-out
             * cards stay listed and show their out-of-stock state.
             *
             * @var bool
             */
            'in_stock' => self::inStock($product),
            /** @var array{url: string, srcset_webp: string|null, srcset_avif: string|null, alt: string|null, alt_bn: string|null, width: int|null, height: int|null}|null */
            'image' => self::image($image),
            /**
             * P7: the cheapest running flash-sale offer on this product (badge + countdown to
             * `ends_at`, server time UTC), or null. Display only; the cart prices it.
             *
             * @var array{promotion_id: int, name: string, name_bn: string|null, ends_at: string, variant_id: int, sale_price: string, regular_price: string, discount_percent: int, remaining: int}|null
             */
            'flash_sale' => FlashSalePayload::forVariants($variants),
        ];
    }

    private static function inStock(Product $product): bool
    {
        $listed = $product->getAttribute('listing_in_stock');
        if ($listed !== null) {
            return (int) $listed === 1;
        }

        return app(StockAvailability::class)->inStockProductIds([$product->id]) !== [];
    }

    /** Whole percent off the was-price, rounded down ("−15%"): never overstates a saving. */
    private static function discountPercent(Money $price, Money $compareAt): int
    {
        return (int) bcdiv(bcmul(bcsub($compareAt->amount, $price->amount, 2), '100', 2), $compareAt->amount, 0);
    }

    /**
     * @return array{url: string, srcset_webp: string|null, srcset_avif: string|null, alt: string|null, alt_bn: string|null, width: int|null, height: int|null}|null
     */
    private static function image(?ProductImage $image): ?array
    {
        $url = $image?->url(self::CARD_WIDTH);
        if ($image === null || $url === null) {
            return null;
        }

        return [
            'url' => $url,
            'srcset_webp' => $image->srcset('webp'),
            'srcset_avif' => $image->srcset('avif'),
            'alt' => $image->alt('en'),
            'alt_bn' => $image->alt('bn'),
            'width' => $image->width,
            'height' => $image->height,
        ];
    }
}
