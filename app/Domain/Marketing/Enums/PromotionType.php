<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Enums;

/**
 * `promotions.type` (DATABASE-DESIGN §3.8). `rules` shape per type is documented in
 * docs/api/cart.md and read by {@see \App\Domain\Marketing\Services\PromotionEngine}.
 */
enum PromotionType: string
{
    /** Percent or fixed-per-unit off every eligible line. */
    case FlashSale = 'flash_sale';
    /** As flash_sale; named separately so reporting can tell them apart. */
    case CategoryDiscount = 'category_discount';
    /** Percent or fixed off the eligible subtotal, by the highest tier reached. */
    case Tiered = 'tiered';
    /** For every `buy` + `get` eligible units, the `get` cheapest are discounted by `percent`. */
    case BuyXGetY = 'buy_x_get_y';
    /** Percent or fixed off once per complete set of `product_ids`. */
    case Bundle = 'bundle';
}
