<?php

declare(strict_types=1);

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Promotion;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Tests\Feature\Cart\CartTestKit;

/*
 * Storefront cart API (P4-CRT-01/02/04) — docs/api/cart.md.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $this->token = CartTestKit::storefrontToken();
});

function cartApi(): Illuminate\Foundation\Testing\TestCase
{
    return test()->withToken(test()->token);
}

/** Add a line, creating the cart; returns the cart token. */
function newCartWith(int $variantId, int $quantity = 1): string
{
    return cartApi()->postJson('/api/v1/storefront/cart/items', ['variant_id' => $variantId, 'quantity' => $quantity])
        ->assertCreated()->json('token');
}

it('needs the storefront token and ability', function (): void {
    $this->postJson('/api/v1/storefront/cart')->assertUnauthorized();

    $weak = App\Models\User::factory()->create()->createToken('other', ['other'])->plainTextToken;
    $this->withToken($weak)->getJson('/api/v1/storefront/cart')->assertForbidden();
});

it('creates an empty cart with an opaque token', function (): void {
    $response = cartApi()->postJson('/api/v1/storefront/cart')->assertCreated();

    expect($response->json('token'))->toBeString()->toHaveLength(36)
        ->and($response->json('items'))->toBe([])
        ->and($response->json('totals'))->toBe([
            'subtotal' => '0.00', 'promotion_discount' => '0.00', 'coupon_discount' => '0.00',
            'discount_total' => '0.00', 'shipping_charge' => null, 'grand_total' => '0.00',
        ])
        ->and($response->json('checkout_ready'))->toBeFalse();
});

it('creates the cart on the first add and returns it priced', function (): void {
    $variant = CartTestKit::variant('1250.00', 5);

    $response = cartApi()->postJson('/api/v1/storefront/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])->assertCreated();

    expect($response->json('item_count'))->toBe(2)
        ->and($response->json('items.0'))->toMatchArray([
            'variant_id' => $variant->id,
            'product' => ['id' => $variant->product_id, 'name' => 'Remote Control Car', 'name_bn' => 'রিমোট কন্ট্রোল গাড়ি', 'slug' => $variant->product->slug],
            'quantity' => 2, 'available_quantity' => 5, 'in_stock' => true, 'sellable' => true,
            'unit_price' => '1250.00', 'price_changed' => false, 'subtotal' => '2500.00', 'discount' => '0.00', 'total' => '2500.00',
        ])
        ->and($response->json('totals.grand_total'))->toBe('2500.00');

    $cart = Cart::query()->sole();
    expect($cart->grand_total->toDecimal())->toBe('2500.00')->and($cart->item_count)->toBe(2);
});

it('adds to the existing line instead of duplicating it', function (): void {
    $variant = CartTestKit::variant('100.00', 10);
    $token = newCartWith($variant->id, 2);

    cartApi()->withHeader(CartTestKit::HEADER, $token)
        ->postJson('/api/v1/storefront/cart/items', ['variant_id' => $variant->id, 'quantity' => 3])
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.quantity', 5);

    expect(CartItem::query()->count())->toBe(1);
});

it('rejects a quantity beyond available stock with a clear problem message', function (): void {
    $variant = CartTestKit::variant('100.00', 3);
    $token = newCartWith($variant->id, 2);

    $response = cartApi()->withHeader(CartTestKit::HEADER, $token)
        ->postJson('/api/v1/storefront/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json');

    expect($response->json('type'))->toBe('https://toymartbd.com/problems/validation-error')
        ->and($response->json('errors'))->toBe([['field' => 'quantity', 'message' => 'Only 3 left in stock.']]);

    // Bangla, Western numerals (rule 36).
    cartApi()->withHeader(CartTestKit::HEADER, $token)
        ->postJson('/api/v1/storefront/cart/items?locale=bn', ['variant_id' => $variant->id, 'quantity' => 2])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.message', 'স্টকে মাত্র 3টি আছে।');

    expect(CartItem::query()->sole()->quantity)->toBe(2);
});

it('rejects an out-of-stock or unsellable variant', function (): void {
    $none = CartTestKit::variant('100.00', 0);
    $archived = CartTestKit::variant('100.00', 5, variant: ['status' => VariantStatus::Archived]);

    cartApi()->postJson('/api/v1/storefront/cart/items', ['variant_id' => $none->id, 'quantity' => 1])
        ->assertUnprocessable()->assertJsonPath('errors.0.message', 'Sorry, this item is out of stock.');
    cartApi()->postJson('/api/v1/storefront/cart/items', ['variant_id' => $archived->id, 'quantity' => 1])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'variant_id');

    expect(Cart::query()->count())->toBe(0);
});

it('validates the add-line body', function (): void {
    cartApi()->postJson('/api/v1/storefront/cart/items', ['variant_id' => 999_999, 'quantity' => 0])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'variant_id')
        ->assertJsonPath('errors.1.field', 'quantity');
});

it('updates and removes lines, checking stock only when raising', function (): void {
    $variant = CartTestKit::variant('100.00', 4);
    $token = newCartWith($variant->id, 4);
    $itemId = CartItem::query()->sole()->id;
    $api = fn () => cartApi()->withHeader(CartTestKit::HEADER, $token);

    $api()->patchJson("/api/v1/storefront/cart/items/{$itemId}", ['quantity' => 5])->assertUnprocessable();
    $api()->patchJson("/api/v1/storefront/cart/items/{$itemId}", ['quantity' => 1])->assertOk()->assertJsonPath('totals.subtotal', '100.00');
    $api()->deleteJson("/api/v1/storefront/cart/items/{$itemId}")->assertOk()->assertJsonPath('items', []);
    $api()->deleteJson("/api/v1/storefront/cart/items/{$itemId}")->assertNotFound();
});

it('does not let one cart touch another cart\'s line', function (): void {
    $variant = CartTestKit::variant('100.00', 4);
    newCartWith($variant->id);
    $foreignItem = CartItem::query()->sole()->id;
    $mine = cartApi()->postJson('/api/v1/storefront/cart')->json('token');

    cartApi()->withHeader(CartTestKit::HEADER, $mine)->deleteJson("/api/v1/storefront/cart/items/{$foreignItem}")->assertNotFound();
    expect(CartItem::query()->count())->toBe(1);
});

it('returns 404 for an unknown, malformed, expired or converted cart token', function (): void {
    $expired = Cart::factory()->expired()->create();
    $converted = Cart::factory()->create(['converted_at' => now()]);

    foreach ([null, 'not-a-uuid', '2f9c8f8e-3b4a-4c55-9f0e-1d2c3b4a5f6e', $expired->token, $converted->token] as $token) {
        $request = cartApi();
        if ($token !== null) {
            $request = $request->withHeader(CartTestKit::HEADER, $token);
        }
        $request->getJson('/api/v1/storefront/cart')->assertNotFound()
            ->assertJsonPath('type', 'https://toymartbd.com/problems/not-found');
    }
});

it('prices delivery for a destination and itemises a breakdown that sums to the total', function (): void {
    $variant = CartTestKit::variant('1000.00', 5);
    Promotion::factory()->create(['name' => 'Puja sale', 'name_bn' => 'পূজার ছাড়', 'rules' => ['type' => 'percent', 'value' => '10']]);
    $token = newCartWith($variant->id, 2);

    $response = cartApi()->withHeader(CartTestKit::HEADER, $token)
        ->getJson('/api/v1/storefront/cart?district_id=8')->assertOk();

    expect($response->json('totals'))->toBe([
        'subtotal' => '2000.00', 'promotion_discount' => '200.00', 'coupon_discount' => '0.00',
        'discount_total' => '200.00', 'shipping_charge' => '130.00', 'grand_total' => '1930.00',
    ])
        ->and($response->json('adjustments'))->toBe([
            ['type' => 'subtotal', 'id' => null, 'label' => null, 'label_bn' => null, 'amount' => '2000.00'],
            ['type' => 'promotion', 'id' => Promotion::query()->value('id'), 'label' => 'Puja sale', 'label_bn' => 'পূজার ছাড়', 'amount' => '-200.00'],
            ['type' => 'shipping', 'id' => null, 'label' => null, 'label_bn' => null, 'amount' => '130.00'],
        ])
        ->and($response->json('delivery.zone'))->toBe('outside_dhaka')
        ->and($response->json('delivery.charge'))->toBe('130.00')
        ->and($response->json('checkout_ready'))->toBeTrue();

    $sum = array_reduce($response->json('adjustments'), fn (string $c, array $r): string => bcadd($c, $r['amount'], 2), '0.00');
    expect($sum)->toBe($response->json('totals.grand_total'));
});

it('validates the destination query', function (): void {
    $token = cartApi()->postJson('/api/v1/storefront/cart')->json('token');

    cartApi()->withHeader(CartTestKit::HEADER, $token)->getJson('/api/v1/storefront/cart?district_id=8&upazila_id=365')
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'upazila_id');
});

it('applies, reports and removes a coupon', function (): void {
    $variant = CartTestKit::variant('1000.00', 5);
    Coupon::factory()->fixed('150')->create(['code' => 'BOISHAKH']);
    Coupon::factory()->create(['code' => 'OLD', 'ends_at' => now()->subDay()]);
    $token = newCartWith($variant->id);
    $api = fn () => cartApi()->withHeader(CartTestKit::HEADER, $token);

    $api()->postJson('/api/v1/storefront/cart/coupon', ['code' => 'old'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0', ['field' => 'code', 'message' => 'This coupon has expired.']);
    $api()->postJson('/api/v1/storefront/cart/coupon?locale=bn', ['code' => 'nothing'])
        ->assertUnprocessable()->assertJsonPath('errors.0.message', 'কুপন কোডটি সঠিক নয়।');

    $api()->postJson('/api/v1/storefront/cart/coupon', ['code' => 'boishakh'])
        ->assertOk()
        ->assertJsonPath('coupon', ['code' => 'BOISHAKH', 'applied' => true, 'reason' => null, 'message' => null, 'discount' => '150.00', 'free_shipping' => false])
        ->assertJsonPath('totals.grand_total', '850.00');

    // The coupon stops qualifying (deactivated): it stays on the cart, flagged with why.
    Coupon::query()->where('code', 'BOISHAKH')->update(['is_active' => false]);
    $api()->getJson('/api/v1/storefront/cart')
        ->assertJsonPath('coupon.applied', false)
        ->assertJsonPath('coupon.reason', 'inactive')
        ->assertJsonPath('totals.grand_total', '1000.00');

    $api()->deleteJson('/api/v1/storefront/cart/coupon')->assertOk()->assertJsonPath('coupon', null);
});

it('flags a line whose variant became unsellable or whose stock dropped, and its price change', function (): void {
    $variant = CartTestKit::variant('500.00', 5);
    $other = CartTestKit::variant('300.00', 5);
    $token = newCartWith($variant->id, 2);
    cartApi()->withHeader(CartTestKit::HEADER, $token)->postJson('/api/v1/storefront/cart/items', ['variant_id' => $other->id, 'quantity' => 1]);

    $variant->update(['price' => '450.00']);
    $other->update(['status' => VariantStatus::Archived]);

    $response = cartApi()->withHeader(CartTestKit::HEADER, $token)->getJson('/api/v1/storefront/cart?district_id=47')->assertOk();

    expect($response->json('items.0'))->toMatchArray(['unit_price' => '450.00', 'added_unit_price' => '500.00', 'price_changed' => true, 'total' => '900.00'])
        ->and($response->json('items.1'))->toMatchArray(['sellable' => false, 'total' => '0.00'])
        ->and($response->json('totals.subtotal'))->toBe('900.00')
        ->and($response->json('checkout_ready'))->toBeFalse();
});
