<?php

declare(strict_types=1);

namespace App\Domain\Cart\Services;

use App\Domain\Cart\Models\Cart;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Services\StockAvailability;
use Illuminate\Validation\ValidationException;

/**
 * Cart-time stock and quantity guard (P4-CRT-01, P4-STO-08 "quantity beyond stock
 * blocked with a message"). Reads availability (`on_hand - reserved`) without locking
 * — checkout's reservation is the decision that counts (DATABASE-DESIGN §4.1).
 * Failures are 422 `validation-error` problems on `quantity` / `variant_id` with a
 * localised message the storefront shows as-is.
 */
final readonly class CartStock
{
    public function __construct(
        private StockAvailability $availability,
        private SellableVariants $sellable,
    ) {}

    /** @throws ValidationException */
    public function assertCanHold(ProductVariant $variant, int $quantity): void
    {
        if (! $this->sellable->isSellable($variant)) {
            throw ValidationException::withMessages(['variant_id' => __('cart.variant.unavailable')]);
        }

        if ($quantity > Cart::MAX_LINE_QUANTITY) {
            throw ValidationException::withMessages(['quantity' => __('cart.quantity.max', ['max' => Cart::MAX_LINE_QUANTITY])]);
        }

        $available = $this->availability->forVariants([$variant->id])[$variant->id] ?? 0;
        if ($quantity > $available) {
            throw ValidationException::withMessages(['quantity' => $available === 0
                ? __('cart.stock.out_of_stock')
                : __('cart.stock.only_left', ['available' => $available])]);
        }
    }
}
