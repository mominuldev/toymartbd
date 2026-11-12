<?php

declare(strict_types=1);

use App\Domain\Cart\Models\Cart;
use App\Domain\Customer\Models\Address;
use App\Domain\Customer\Models\Customer;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\VerificationStatus;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Events\OrderPutOnHold;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;

/*
 * POST /storefront/checkout (P4-ORD-02, P4-STO-09) — docs/api/checkout.md.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $this->token = CartTestKit::storefrontToken();
    config(['risk.first_order_hold_threshold' => '100000.00']);
});

/** @param  array<string, mixed>  $body */
function checkout(string $cartToken, array $body, ?string $key = null): Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->postJson('/api/v1/storefront/checkout', $body, [
        CartTestKit::HEADER => $cartToken,
        'Idempotency-Key' => $key ?? (string) Str::uuid(),
    ]);
}

it('places a guest COD order with Bangla name and address, and returns the confirmation object', function (): void {
    $variant = CartTestKit::variant('1000.00', 5);
    $cart = CheckoutKit::cart([$variant->id => 2]);

    $response = checkout($cart, CheckoutKit::payload($cart))->assertCreated();

    $order = Order::query()->sole();
    $response->assertHeader('Location', '/api/v1/storefront/orders/'.$order->number);
    expect($response->json('number'))->toBe($order->number)->toMatch('/^TMB-\d{2}-\d{5}$/')
        ->and($response->json('stage'))->toBe('placed')
        ->and($response->json('payment'))->toBe(['method' => 'cod', 'status' => 'unpaid', 'next_action' => null, 'online' => false, 'retry_allowed' => false, 'expires_at' => null])
        ->and($response->json('contact.name'))->toBe('রাহেলা খাতুন')
        ->and($response->json('contact.phone_masked'))->not->toContain('12345')
        ->and($response->json('shipping.address_line'))->toBe('বাড়ি ১২, রোড ৫, ব্লক সি')
        ->and($response->json('shipping.landmark'))->toBe('জামে মসজিদের পাশে, ৩য় তলা')
        ->and($response->json('shipping.area'))->toBe('আগ্রাবাদ')
        ->and($response->json('shipping.district.id'))->toBe(CheckoutKit::DISTRICT)
        ->and($response->json('shipping.upazila.id'))->toBe(CheckoutKit::UPAZILA)
        ->and($response->json('items.0'))->toMatchArray(['variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => '1000.00', 'name_bn' => 'রিমোট কন্ট্রোল গাড়ি'])
        ->and($response->json('totals.subtotal'))->toBe('2000.00')
        ->and($response->json('totals.shipping_charge'))->toBe($order->shipping_charge->toDecimal())
        ->and($response->json('totals.grand_total'))->toBe($order->grand_total->toDecimal())
        ->and($response->json('totals.due_total'))->toBe($order->grand_total->toDecimal())
        ->and($response->json('timeline'))->toHaveCount(1)
        ->and($response->json('timeline.0.stage'))->toBe('placed');

    // Never exposed: cost, risk, the full phone, the customer id.
    $raw = $response->getContent();
    expect($raw)->not->toContain('cost')->not->toContain('risk')->not->toContain('8801712345678')->not->toContain('customer_id');

    // Stored: E.164 phone, Bangla snapshot intact (utf8mb4), the guest customer and address book entry.
    expect($order->shipping_phone)->toBe('+8801712345678')
        ->and($order->shipping_name)->toBe('রাহেলা খাতুন')
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->verification_status)->toBe(VerificationStatus::NotRequired);
    $customer = Customer::query()->sole();
    expect($customer->phone)->toBe('+8801712345678')
        ->and($customer->name)->toBe('রাহেলা খাতুন')
        ->and($order->customer_id)->toBe($customer->id);
    $address = Address::query()->sole();
    expect($address->landmark)->toBe('জামে মসজিদের পাশে, ৩য় তলা')
        ->and($address->upazila_id)->toBe(CheckoutKit::UPAZILA)
        ->and($address->is_default_shipping)->toBeTrue();

    // The cart is closed: its token no longer resolves as a cart.
    expect(Cart::query()->where('token', $cart)->value('converted_at'))->not->toBeNull();
    $this->withToken($this->token)->getJson('/api/v1/storefront/cart', [CartTestKit::HEADER => $cart])->assertNotFound();
});

it('writes the cost snapshot, takes the reservation, opens the COD payment and records the placed event', function (): void {
    Event::fake([OrderPlaced::class]);
    $variant = CartTestKit::variant('1000.00', 5, variant: ['cost_price' => '640.00']);
    $cart = CheckoutKit::cart([$variant->id => 3]);

    checkout($cart, CheckoutKit::payload($cart))->assertCreated();
    $order = Order::query()->sole();

    $item = OrderItem::query()->sole();
    expect((string) $item->cost_price_snapshot)->toBe('640.0000')
        ->and($item->quantity)->toBe(3)
        ->and($item->line_total->toDecimal())->toBe('3000.00')
        ->and($item->sku)->toBe($variant->sku);

    $level = StockLevel::query()->where('variant_id', $variant->id)->sole();
    expect($level->on_hand)->toBe(5)->and($level->reserved)->toBe(3);

    $payment = DB::table('payments')->where('order_id', $order->id)->sole();
    expect($payment->method)->toBe('cod')
        ->and(App\Support\Money::of((string) $payment->amount)->equals($order->grand_total))->toBeTrue();

    expect(DB::table('order_events')->where('order_id', $order->id)->where('type', 'placed')->count())->toBe(1);
    Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $e): bool => $e->orderId === $order->id);
});

it('creates one order for a double submit with the same Idempotency-Key', function (): void {
    $variant = CartTestKit::variant('1000.00', 5);
    $cart = CheckoutKit::cart([$variant->id => 1]);
    $body = CheckoutKit::payload($cart);
    $key = (string) Str::uuid();

    $first = checkout($cart, $body, $key)->assertCreated();
    $second = checkout($cart, $body, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('number'))->toBe($first->json('number'))
        ->and(Order::query()->count())->toBe(1)
        ->and(StockLevel::query()->where('variant_id', $variant->id)->value('reserved'))->toBe(1);
});

it('refuses a second order from the same cart under a new key', function (): void {
    $variant = CartTestKit::variant('1000.00', 5);
    $cart = CheckoutKit::cart([$variant->id => 1]);
    $body = CheckoutKit::payload($cart);

    checkout($cart, $body)->assertCreated();
    checkout($cart, $body)->assertNotFound()->assertJsonPath('type', 'https://toymartbd.com/problems/not-found');

    expect(Order::query()->count())->toBe(1);
});

it('requires an Idempotency-Key', function (): void {
    $variant = CartTestKit::variant();
    $cart = CheckoutKit::cart([$variant->id => 1]);

    $this->withToken($this->token)
        ->postJson('/api/v1/storefront/checkout', CheckoutKit::payload($cart), [CartTestKit::HEADER => $cart])
        ->assertStatus(400)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/idempotency-key-missing');
});

it('blocks a price mismatch with price-changed and writes nothing', function (): void {
    $variant = CartTestKit::variant('1000.00', 5);
    $cart = CheckoutKit::cart([$variant->id => 1]);
    $real = CheckoutKit::total($cart);

    checkout($cart, CheckoutKit::payload($cart, ['expected_total' => '999.00']))
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://toymartbd.com/problems/price-changed')
        ->assertJsonPath('errors.0.field', 'expected_total')
        ->assertJsonPath('errors.0.message', fn (string $m): bool => str_contains($m, $real));

    expect(Order::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0)
        ->and(StockLevel::query()->where('variant_id', $variant->id)->value('reserved'))->toBe(0)
        ->and(Cart::query()->where('token', $cart)->value('converted_at'))->toBeNull();
});

it('blocks when a price changed between viewing and placing', function (): void {
    $variant = CartTestKit::variant('1000.00', 5);
    $cart = CheckoutKit::cart([$variant->id => 1]);
    $body = CheckoutKit::payload($cart);

    $variant->update(['price' => '1100.00']);

    checkout($cart, $body)->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/price-changed');
    expect(Order::query()->count())->toBe(0);
});

it('refuses the whole order when stock ran out, never a partial one', function (): void {
    $a = CartTestKit::variant('500.00', 5);
    $b = CartTestKit::variant('700.00', 3);
    $cart = CheckoutKit::cart([$a->id => 2, $b->id => 2]);
    $body = CheckoutKit::payload($cart);

    // Someone else reserves 2 of b's 3 units in the meantime.
    DB::transaction(fn () => app(App\Domain\Inventory\Services\StockReservations::class)->reserve([$b->id => 2]));

    checkout($cart, $body)
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/insufficient-stock')
        ->assertJsonPath('errors.0.field', 'items.'.$b->id);

    expect(Order::query()->count())->toBe(0)
        ->and(StockLevel::query()->where('variant_id', $a->id)->value('reserved'))->toBe(0)
        ->and(StockLevel::query()->where('variant_id', $b->id)->value('reserved'))->toBe(2);
});

it('enforces the per-phone coupon limit at checkout', function (): void {
    Coupon::factory()->fixed('100.00')->create(['code' => 'EID100', 'usage_limit_per_customer' => 1]);
    $variant = CartTestKit::variant('1000.00', 10);

    $first = CheckoutKit::cart([$variant->id => 1], 'EID100');
    checkout($first, CheckoutKit::payload($first))->assertCreated()->assertJsonPath('totals.coupon_discount', '100.00');
    expect(DB::table('coupon_usages')->count())->toBe(1);

    // A new cart, same phone, same coupon: the limit is reached for this phone.
    $second = CheckoutKit::cart([$variant->id => 1], 'EID100');
    checkout($second, CheckoutKit::payload($second, ['expected_total' => '1030.00']))
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/validation-error')
        ->assertJsonPath('errors.0.field', 'coupon');

    // Another phone may still use it.
    $third = CheckoutKit::cart([$variant->id => 1], 'EID100');
    checkout($third, CheckoutKit::payload($third, ['phone' => '01812345678']))->assertCreated();

    expect(Order::query()->count())->toBe(2)->and(DB::table('coupon_usages')->count())->toBe(2);
});

it('reuses the customer and address for a returning phone in any spelling', function (): void {
    $variant = CartTestKit::variant('1000.00', 10);

    $a = CheckoutKit::cart([$variant->id => 1]);
    checkout($a, CheckoutKit::payload($a))->assertCreated();
    $b = CheckoutKit::cart([$variant->id => 1]);
    checkout($b, CheckoutKit::payload($b, ['phone' => '+880 1712-345678', 'name' => 'Rahela Khatun']))->assertCreated();

    expect(Customer::query()->count())->toBe(1)
        ->and(Customer::query()->value('name'))->toBe('রাহেলা খাতুন')
        ->and(Order::query()->pluck('shipping_name')->all())->toBe(['রাহেলা খাতুন', 'Rahela Khatun'])
        ->and(Address::query()->count())->toBe(2);
});

it('holds a first order from an unknown phone above the threshold, never cancels it', function (): void {
    config(['risk.first_order_hold_threshold' => '3000.00']);
    Event::fake([OrderPutOnHold::class]);
    $variant = CartTestKit::variant('1600.00', 10);

    $cart = CheckoutKit::cart([$variant->id => 2]);
    $response = checkout($cart, CheckoutKit::payload($cart))->assertCreated();

    $order = Order::query()->sole();
    expect($order->status)->toBe(OrderStatus::OnHold)
        ->and($order->verification_status)->toBe(VerificationStatus::Pending)
        ->and($order->risk_flags['hold_reasons'])->toContain('first_order_above_threshold')
        // The customer is never told the order was flagged.
        ->and($response->json('stage'))->toBe('placed')
        ->and($response->json('timeline'))->toHaveCount(1);
    Event::assertDispatched(OrderPutOnHold::class);

    // The same phone's second order is no longer a first order: not held for that reason.
    $again = CheckoutKit::cart([$variant->id => 2]);
    checkout($again, CheckoutKit::payload($again))->assertCreated();
    expect(Order::query()->latest('id')->first()?->status)->toBe(OrderStatus::Pending);
});

it('does not hold a first order below the threshold', function (): void {
    config(['risk.first_order_hold_threshold' => '3000.00']);
    $variant = CartTestKit::variant('1000.00', 10);
    $cart = CheckoutKit::cart([$variant->id => 1]);

    checkout($cart, CheckoutKit::payload($cart))->assertCreated();

    $order = Order::query()->sole();
    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->risk_flags['signals'][0]['code'])->toBe('first_order');
});

it('validates the form', function (array $override, string $field): void {
    $variant = CartTestKit::variant();
    $cart = CheckoutKit::cart([$variant->id => 1]);

    checkout($cart, CheckoutKit::payload($cart, $override + ['expected_total' => '1130.00']))
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/validation-error')
        ->assertJsonFragment(['field' => $field]);

    expect(Order::query()->count())->toBe(0);
})->with([
    'citycell 011' => [['phone' => '01112345678'], 'phone'],
    '012' => [['phone' => '01212345678'], 'phone'],
    'short phone' => [['phone' => '0171234'], 'phone'],
    'no name' => [['name' => ''], 'name'],
    'no address' => [['address_line' => ''], 'address_line'],
    'no area' => [['area' => ''], 'area'],
    'upazila of another district' => [['upazila_id' => 1], 'upazila_id'],
    'upazila missing outside a city' => [['upazila_id' => null], 'upazila_id'],
    'online payment' => [['payment_method' => 'bkash'], 'payment_method'],
    'bad total' => [['expected_total' => 'abc'], 'expected_total'],
]);

it('refuses an empty cart as not checkout-ready', function (): void {
    $variant = CartTestKit::variant();
    $cart = CheckoutKit::cart([$variant->id => 1]);
    Cart::query()->where('token', $cart)->sole()->items()->delete();

    checkout($cart, CheckoutKit::payload($cart, ['expected_total' => '0.00']))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'cart');
});

it('returns 404 for an unknown cart token', function (): void {
    checkout((string) Str::uuid(), CheckoutKit::payload('x', ['expected_total' => '1.00']))->assertNotFound();
});

it('localises the price-changed message in Bangla', function (): void {
    $variant = CartTestKit::variant('1000.00', 5);
    $cart = CheckoutKit::cart([$variant->id => 1]);

    $this->withToken($this->token)
        ->postJson('/api/v1/storefront/checkout?locale=bn', CheckoutKit::payload($cart, ['expected_total' => '1.00']), [
            CartTestKit::HEADER => $cart, 'Idempotency-Key' => (string) Str::uuid(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.message', fn (string $m): bool => preg_match('/\p{Bengali}/u', $m) === 1);
});

it('sells exactly the stock when more checkouts than units arrive (sequential, every driver)', function (): void {
    $variant = CartTestKit::variant('1000.00', 5);
    $carts = [];
    for ($i = 0; $i < 10; $i++) {
        $carts[] = CheckoutKit::cart([$variant->id => 1]);
    }

    $statuses = [];
    foreach ($carts as $i => $cart) {
        $statuses[] = checkout($cart, CheckoutKit::payload($cart, ['phone' => sprintf('0171%07d', $i)]))->status();
    }

    expect(array_count_values($statuses))->toBe([201 => 5, 409 => 5])
        ->and(Order::query()->count())->toBe(5)
        ->and(StockLevel::query()->where('variant_id', $variant->id)->value('reserved'))->toBe(5);
});
