<?php

declare(strict_types=1);

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\DTOs\PricedCart;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Cart\Services\CartPricing;
use Illuminate\Support\Facades\DB;

/**
 * Merge a guest cart into the customer's cart on login (P4-CRT-01). Call it from the
 * customer-login flow with the guest cart token the storefront forwarded.
 *
 * - No open customer cart → the guest cart simply becomes the customer's.
 * - Otherwise each guest line moves across; a variant on both carts becomes ONE line
 *   with the quantities added (capped at Cart::MAX_LINE_QUANTITY) — never two lines.
 *   Stock is not enforced here (a login must not fail on stock): the cart response
 *   flags any line above availability and checkout refuses it.
 * - The customer's coupon wins; the guest's is kept only when the customer had none.
 * - The guest cart is closed (`merged_into_cart_id`), so its token stops resolving.
 *
 * Both carts are locked in ascending id order, so two concurrent merges cannot deadlock.
 * An unknown/expired guest token is not an error: the customer's cart is returned
 * (created if needed).
 */
final readonly class MergeGuestCart
{
    public function __construct(private CartPricing $pricing) {}

    public function handle(?string $guestToken, int $customerId): PricedCart
    {
        return DB::transaction(function () use ($guestToken, $customerId): PricedCart {
            $guestId = $guestToken === null ? null : Cart::query()->open()->where('token', strtolower($guestToken))->value('id');
            $customerCartId = Cart::query()->open()->where('customer_id', $customerId)->latest('id')->value('id');

            $ids = array_values(array_filter([$guestId, $customerCartId]));
            sort($ids);
            $locked = Cart::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $guest = $guestId === null ? null : $locked->get($guestId);
            $target = $customerCartId === null ? null : $locked->get($customerCartId);

            if ($guest !== null && ($target === null || $target->id === $guest->id)) {
                $guest->customer_id = $customerId;

                return $this->pricing->priceAndStore($guest);
            }

            $target ??= CreateCart::newCart($customerId);
            if ($guest === null) {
                return $this->pricing->priceAndStore($target);
            }

            $existing = CartItem::query()->where('cart_id', $target->id)->get()->keyBy('variant_id');
            foreach (CartItem::query()->where('cart_id', $guest->id)->orderBy('id')->get() as $item) {
                $same = $existing->get($item->variant_id);
                if ($same === null) {
                    $item->cart_id = $target->id;
                    $item->save();

                    continue;
                }
                $same->quantity = min(Cart::MAX_LINE_QUANTITY, $same->quantity + $item->quantity);
                $same->save();
                $item->delete();
            }

            if ($target->coupon_id === null && $guest->coupon_id !== null) {
                $target->coupon_id = $guest->coupon_id;
            }

            $guest->merged_into_cart_id = $target->id;
            $guest->coupon_id = null;
            $guest->save();

            return $this->pricing->priceAndStore($target);
        });
    }
}
