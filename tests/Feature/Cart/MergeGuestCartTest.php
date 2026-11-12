<?php

declare(strict_types=1);

use App\Domain\Cart\Actions\AddCartLine;
use App\Domain\Cart\Actions\MarkCartConverted;
use App\Domain\Cart\Actions\MergeGuestCart;
use App\Domain\Cart\Exceptions\CartNotFound;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Cart\Services\CartResolver;
use App\Domain\Marketing\Models\Coupon;
use Tests\Feature\Cart\CartTestKit;

/*
 * P4-CRT-01 — a guest cart merges into the account cart on login without duplicating lines.
 */

const MERGE_CUSTOMER_ID = 501;

function guestCart(array $lines): Cart
{
    $token = null;
    foreach ($lines as $variantId => $qty) {
        $token = app(AddCartLine::class)->handle($token, $variantId, $qty)->cart->token;
    }

    return Cart::query()->where('token', $token)->sole();
}

it('merges a guest cart into the customer cart without duplicating lines', function (): void {
    $shared = CartTestKit::variant('500.00', 20);
    $guestOnly = CartTestKit::variant('200.00', 20);
    $customerOnly = CartTestKit::variant('300.00', 20);

    $customer = guestCart([$shared->id => 2, $customerOnly->id => 1]);
    $customer->update(['customer_id' => MERGE_CUSTOMER_ID]);
    $guest = guestCart([$shared->id => 3, $guestOnly->id => 1]);

    $merged = app(MergeGuestCart::class)->handle($guest->token, MERGE_CUSTOMER_ID);

    expect($merged->cart->id)->toBe($customer->id)
        ->and(CartItem::query()->where('cart_id', $customer->id)->orderBy('variant_id')->pluck('quantity', 'variant_id')->all())
        ->toBe([$shared->id => 5, $guestOnly->id => 1, $customerOnly->id => 1])
        ->and(CartItem::query()->where('cart_id', $guest->id)->count())->toBe(0)
        ->and($merged->breakdown->subtotal->toDecimal())->toBe('3000.00')
        ->and($merged->cart->refresh()->grand_total->toDecimal())->toBe('3000.00')
        ->and($guest->refresh()->merged_into_cart_id)->toBe($customer->id);

    // The guest token no longer resolves.
    expect(fn () => app(CartResolver::class)->find($guest->token))->toThrow(CartNotFound::class);
});

it('caps a merged quantity at the per-line maximum', function (): void {
    $v = CartTestKit::variant('10.00', 200);
    $customer = guestCart([$v->id => 60]);
    $customer->update(['customer_id' => MERGE_CUSTOMER_ID]);
    $guest = guestCart([$v->id => 60]);

    app(MergeGuestCart::class)->handle($guest->token, MERGE_CUSTOMER_ID);

    expect(CartItem::query()->sole()->quantity)->toBe(Cart::MAX_LINE_QUANTITY);
});

it('adopts the guest cart when the customer has none', function (): void {
    $v = CartTestKit::variant();
    $guest = guestCart([$v->id => 1]);

    $result = app(MergeGuestCart::class)->handle($guest->token, MERGE_CUSTOMER_ID);

    expect($result->cart->id)->toBe($guest->id)
        ->and($guest->refresh()->customer_id)->toBe(MERGE_CUSTOMER_ID)
        ->and(Cart::query()->count())->toBe(1);
});

it('keeps the customer\'s coupon, falling back to the guest\'s', function (): void {
    $v = CartTestKit::variant('1000.00');
    $mine = Coupon::factory()->create(['code' => 'MINE']);
    $theirs = Coupon::factory()->create(['code' => 'THEIRS']);

    $customer = guestCart([$v->id => 1]);
    $customer->update(['customer_id' => MERGE_CUSTOMER_ID, 'coupon_id' => $mine->id]);
    $guest = guestCart([$v->id => 1]);
    $guest->update(['coupon_id' => $theirs->id]);
    expect(app(MergeGuestCart::class)->handle($guest->token, MERGE_CUSTOMER_ID)->cart->coupon_id)->toBe($mine->id);

    $customer->update(['coupon_id' => null]);
    $guest2 = guestCart([$v->id => 1]);
    $guest2->update(['coupon_id' => $theirs->id]);
    expect(app(MergeGuestCart::class)->handle($guest2->token, MERGE_CUSTOMER_ID)->cart->coupon_id)->toBe($theirs->id);
});

it('returns the customer cart for an unknown guest token, creating one if needed', function (): void {
    $result = app(MergeGuestCart::class)->handle('2f9c8f8e-3b4a-4c55-9f0e-1d2c3b4a5f6e', MERGE_CUSTOMER_ID);

    expect($result->cart->customer_id)->toBe(MERGE_CUSTOMER_ID)
        ->and(app(MergeGuestCart::class)->handle(null, MERGE_CUSTOMER_ID)->cart->id)->toBe($result->cart->id);
});

it('closes a converted cart', function (): void {
    $cart = guestCart([CartTestKit::variant()->id => 1]);

    app(MarkCartConverted::class)->handle($cart->id, 77);
    app(MarkCartConverted::class)->handle($cart->id, 78);

    expect($cart->refresh()->order_id)->toBe(77)
        ->and(fn () => app(CartResolver::class)->find($cart->token))->toThrow(CartNotFound::class);
});
