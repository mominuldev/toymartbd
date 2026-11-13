<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Enums;

/**
 * `promotions.type` (DATABASE-DESIGN §3.8). `rules` shape per type is documented in
 * docs/api/promotions.md and read by {@see \App\Domain\Marketing\Services\PromotionEngine}.
 */
enum PromotionType: string
{
    /** Percent or fixed-per-unit off every eligible line (the general "percentage / fixed" promotion). */
    case Discount = 'discount';
    /**
     * Hard-windowed sale. With `flash_sale_items` rows: each listed variant at its sale
     * price for a limited allotment. Without: percent/fixed like `discount` (Phase 4 rows).
     */
    case FlashSale = 'flash_sale';
    /** As `discount`; the Phase 4 name, kept so existing rows and reports still read. */
    case CategoryDiscount = 'category_discount';
    /** Percent or fixed off the eligible subtotal, by the highest tier reached. */
    case Tiered = 'tiered';
    /** For every `buy` + `get` eligible units, the `get` cheapest are discounted by `percent`. */
    case BuyXGetY = 'buy_x_get_y';
    /** Percent or fixed off — or a fixed `price` for — each complete set of `product_ids`. */
    case Bundle = 'bundle';
    /** Delivery becomes free when the eligible goods (after discounts) reach `min_subtotal`. */
    case FreeDelivery = 'free_delivery';

    /** Discounts goods lines (everything except free delivery). */
    public function discountsLines(): bool
    {
        return $this !== self::FreeDelivery;
    }
}
