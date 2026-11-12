<?php

declare(strict_types=1);

use App\Domain\Cart\Actions\AddCartLine;
use App\Domain\Cart\Actions\MergeGuestCart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Catalog\Enums\RecommendationPlacement;
use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Models\OrderItem;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Inventory\InventoryOpsKit;

/*
 * Rule 25d — the recommendation placement that added a cart line is carried on
 * `cart_items.recommendation_source` and stamped onto `order_items.recommendation_source`
 * by PlaceOrder, so the P&L reads a measurement, not a belief.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $this->token = CartTestKit::storefrontToken();
    config(['risk.first_order_hold_threshold' => '100000.00']);
});

/** @param  array<string, mixed>  $body */
function addToCart(?string $cartToken, array $body): Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->postJson('/api/v1/storefront/cart/items', $body, $cartToken === null ? [] : [CartTestKit::HEADER => $cartToken]);
}

it('stamps each order item with the placement that added its cart line', function (): void {
    $plain = CartTestKit::variant('500.00', 5);
    $crossSell = CartTestKit::variant('300.00', 5);

    $cart = addToCart(null, ['variant_id' => $plain->id, 'quantity' => 1])->assertCreated()->json('token');
    addToCart($cart, ['variant_id' => $crossSell->id, 'quantity' => 2, 'recommendation_source' => 'cart_cross_sell'])->assertOk();

    expect(CartItem::query()->where('variant_id', $crossSell->id)->value('recommendation_source'))->toBe(RecommendationPlacement::CartCrossSell);

    $this->withToken($this->token)->postJson('/api/v1/storefront/checkout', CheckoutKit::payload($cart), [
        CartTestKit::HEADER => $cart,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertCreated();

    expect(OrderItem::query()->where('variant_id', $crossSell->id)->value('recommendation_source'))->toBe('cart_cross_sell')
        ->and(OrderItem::query()->where('variant_id', $plain->id)->value('recommendation_source'))->toBeNull();

    InventoryOpsKit::assertInvariants();
});

it('refuses an unknown placement, and never a checkout one (rule 25b)', function (string $placement): void {
    $variant = CartTestKit::variant('500.00', 5);

    addToCart(null, ['variant_id' => $variant->id, 'quantity' => 1, 'recommendation_source' => $placement])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'recommendation_source');
    expect(CartItem::query()->count())->toBe(0);
})->with(['checkout_upsell', 'homepage']);

it('lets the latest placement win, and a plain re-add keep the one already there', function (): void {
    $variant = CartTestKit::variant('500.00', 10);
    $add = app(AddCartLine::class);

    $cart = $add->handle(null, $variant->id, 1, RecommendationPlacement::PdpCrossSell)->cart->token;
    $add->handle($cart, $variant->id, 1);
    expect(CartItem::query()->sole()->recommendation_source)->toBe(RecommendationPlacement::PdpCrossSell);

    $add->handle($cart, $variant->id, 1, RecommendationPlacement::PdpFbt);
    expect(CartItem::query()->sole()->recommendation_source)->toBe(RecommendationPlacement::PdpFbt)
        ->and(CartItem::query()->sole()->quantity)->toBe(3);
});

it('carries the placement through a guest-cart merge on sign-in', function (): void {
    $kept = CartTestKit::variant('500.00', 10);
    $moved = CartTestKit::variant('400.00', 10);
    $customer = Customer::factory()->create(['phone' => '+8801712345678', 'name' => 'সাদিয়া ইসলাম']);
    $add = app(AddCartLine::class);

    // The customer's own cart has `$kept` without a placement.
    $own = $add->handle(null, $kept->id, 1)->cart;
    $own->customer_id = $customer->id;
    $own->save();

    $guest = $add->handle(null, $kept->id, 1, RecommendationPlacement::PdpUpsell)->cart->token;
    $add->handle($guest, $moved->id, 1, RecommendationPlacement::CartCrossSell);

    $merged = app(MergeGuestCart::class)->handle($guest, $customer->id)->cart;

    $items = CartItem::query()->where('cart_id', $merged->id)->get()->keyBy('variant_id');
    expect($items[$kept->id]->recommendation_source)->toBe(RecommendationPlacement::PdpUpsell)
        ->and($items[$moved->id]->recommendation_source)->toBe(RecommendationPlacement::CartCrossSell);
});
