<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Cart\Actions\CreateCart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Marketing\Enums\CouponRejection;
use App\Domain\Marketing\Exceptions\CouponRejected;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Ordering\Checkout\CheckoutNotReady;
use App\Domain\Ordering\Checkout\PriceChanged;
use App\Domain\Ordering\DTOs\ManualOrderData;
use App\Domain\Ordering\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * A staff-entered order — phone, Facebook, WhatsApp (P4-ORD-06). It IS a web checkout:
 * the lines become a staff cart and {@see PlaceOrder} places it, so pricing, the stock
 * reservation (FOR UPDATE, ascending variant id), customer-by-phone, cost snapshots,
 * the COD payment, coupon usage, risk hold and the `placed` event are the same code
 * path, and the order has the same shape. Only `source`, `created_by` and the internal
 * note differ.
 *
 * One transaction around both steps: if PlaceOrder refuses (stock, price changed, not
 * serviceable) the staff cart is rolled back with it, so no orphan cart is left behind.
 * Deadlock victims retry here — PlaceOrder's own retry does not apply when nested.
 *
 * @throws CheckoutNotReady unknown coupon, unsellable line, address not serviced
 * @throws PriceChanged the re-priced total differs from the quote the operator saw
 * @throws InsufficientStock some line cannot be reserved — nothing is written
 * @throws CouponRejected a coupon limit was reached since the quote
 */
final readonly class PlaceManualOrder
{
    private const int ATTEMPTS = 3;

    public function __construct(private PlaceOrder $placeOrder) {}

    public function handle(ManualOrderData $data): Order
    {
        return DB::transaction(function () use ($data): Order {
            $cart = CreateCart::newCart();

            $lines = $data->lines;
            ksort($lines);
            $variants = ProductVariant::query()->whereKey(array_keys($lines))->get()->keyBy('id');
            foreach ($lines as $variantId => $quantity) {
                $variant = $variants[$variantId] ?? null;
                if (! $variant instanceof ProductVariant) {
                    throw new CheckoutNotReady([['field' => 'lines.'.$variantId, 'message' => (string) __('cart.variant.unavailable')]]);
                }
                CartItem::query()->create([
                    'cart_id' => $cart->id,
                    'variant_id' => $variantId,
                    'quantity' => $quantity,
                    'unit_price' => $variant->price,
                    'line_total' => $variant->price->times($quantity),
                    'added_at' => now(),
                ]);
            }

            if ($data->couponCode !== null && trim($data->couponCode) !== '') {
                $coupon = Coupon::findByCode($data->couponCode)
                    ?? throw new CheckoutNotReady([['field' => 'coupon_code', 'message' => CouponRejection::NotFound->message()]]);
                $cart->coupon_id = $coupon->id;
                $cart->save();
            }

            $order = $this->placeOrder->handle($data->toCheckout($cart->token));

            // Who keyed it in, and the note staff keep for themselves (never shown to the customer).
            $order->forceFill(['created_by' => $data->userId, 'internal_note' => $data->internalNote])->save();

            return $order;
        }, self::ATTEMPTS);
    }
}
