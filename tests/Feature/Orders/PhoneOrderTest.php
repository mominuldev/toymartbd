<?php

declare(strict_types=1);

use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Ordering\Enums\OrderSource;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Orders\Support;
use Tests\Support\RoleMatrix;

/*
 * P4-ORD-06 — manual (phone / Facebook / WhatsApp) orders: the same pricing pipeline
 * and the same PlaceOrder as web checkout, so the same order shape with its own source.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    config(['risk.first_order_hold_threshold' => '100000.00']);
});

/** @param  array<string, mixed>  $body */
function adminCreate(array $body, ?string $key = null): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/admin/orders', $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
}

/** @param  array<string, mixed>  $body */
function adminQuote(array $body): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/admin/orders/quote', $body);
}

it('quotes through the checkout pricing pipeline and returns the total the web cart would show', function (): void {
    Support::actingAs(Role::OrderOperator);
    $variant = CartTestKit::variant('1000.00', 5);
    $webTotal = CheckoutKit::total(CheckoutKit::cart([$variant->id => 2]));

    $json = adminQuote(Support::body([$variant->id => 2]))->assertOk()->json();

    expect($json['grand_total'])->toBe($webTotal)
        ->and($json['subtotal'])->toBe('2000.00')
        ->and($json['shipping_charge'])->not->toBeNull()
        ->and($json['ready'])->toBeTrue()
        ->and($json['lines'][0])->toMatchArray(['variant_id' => $variant->id, 'quantity' => 2, 'available' => 5]);

    // Without a destination: no delivery line, not ready yet only because nothing blocks — shipping is null.
    $partial = adminQuote(['lines' => [['variant_id' => $variant->id, 'quantity' => 1]]])->assertOk()->json();
    expect($partial['shipping_charge'])->toBeNull()->and($partial['delivery'])->toBeNull();
});

it('creates a phone order with the same shape as a web order, but source=phone and created_by', function (): void {
    $user = Support::actingAs(Role::OrderOperator);
    Event::fake([OrderPlaced::class]);
    $variant = CartTestKit::variant('1000.00', 5, variant: ['cost_price' => '640.00']);

    // The reference: a web checkout of the same lines and address.
    $webCart = CheckoutKit::cart([$variant->id => 2]);
    $web = app(App\Domain\Ordering\Actions\PlaceOrder::class)->handle(CheckoutKit::data($webCart, name: 'রাহেলা খাতুন'));

    $total = adminQuote(Support::body([$variant->id => 2]))->json('grand_total');
    $response = adminCreate(Support::body([$variant->id => 2], ['expected_total' => $total, 'internal_note' => 'Messenger 1:40 pm', 'customer_note' => 'Gift wrap']))->assertCreated();

    $phone = Order::query()->findOrFail($response->json('id'));
    expect($phone->source)->toBe(OrderSource::Phone)
        ->and($phone->created_by)->toBe($user->id)
        ->and($phone->internal_note)->toBe('Messenger 1:40 pm')
        ->and($phone->customer_note)->toBe('Gift wrap')
        ->and($phone->cart_id)->not->toBeNull()
        ->and($response->json('source'))->toBe('phone');

    // Same shape: every money column, status, customer, address snapshot, items and payment.
    $shape = static fn (Order $o): array => [
        'status' => $o->status->value,
        'payment_status' => $o->payment_status->value,
        'fulfillment_status' => $o->fulfillment_status->value,
        'verification_status' => $o->verification_status->value,
        'payment_method' => $o->payment_method,
        'customer_id' => $o->customer_id,
        'totals' => [(string) $o->subtotal, (string) $o->discount_total, (string) $o->shipping_charge, (string) $o->grand_total, (string) $o->due_total],
        'shipping' => [$o->shipping_name, $o->shipping_phone, $o->shipping_district_id, $o->shipping_upazila_id, $o->shipping_division, $o->shipping_district, $o->shipping_upazila, $o->shipping_area, $o->shipping_address],
        'items' => $o->items->map(fn ($i) => [$i->variant_id, $i->sku, $i->quantity, (string) $i->unit_price, (string) $i->line_total, (string) $i->cost_price_snapshot])->all(),
        'payments' => $o->payments->map(fn ($p) => [$p->method->value, $p->status->value, (string) $p->amount])->all(),
        'events' => $o->events->map(fn ($e) => $e->type->value)->all(),
    ];
    $web->load(['items', 'payments', 'events']);
    $phone->load(['items', 'payments', 'events']);
    expect($shape($phone))->toBe($shape($web))
        ->and($web->source)->toBe(OrderSource::Web)
        ->and($phone->items->sole()->cost_price_snapshot)->toBe('640.0000')
        ->and(StockLevel::query()->where('variant_id', $variant->id)->value('reserved'))->toBe(4);

    Event::assertDispatched(OrderPlaced::class, 2);
    Support::assertStockInvariants();
    Support::assertOrderFinancials($phone);
});

it('records the channel: facebook and whatsapp orders keep their source', function (string $source): void {
    Support::actingAs(Role::Manager);
    $variant = CartTestKit::variant('500.00', 5);
    $total = adminQuote(Support::body([$variant->id => 1]))->json('grand_total');

    $id = adminCreate(Support::body([$variant->id => 1], ['expected_total' => $total, 'source' => $source]))->assertCreated()->json('id');

    expect(Order::query()->findOrFail($id)->source->value)->toBe($source);
})->with(['facebook', 'whatsapp']);

it('refuses web as a staff source, a missing landmark and a 011 number', function (): void {
    Support::actingAs(Role::OrderOperator);
    $variant = CartTestKit::variant('500.00', 5);

    $errors = adminCreate(Support::body([$variant->id => 1], ['source' => 'web', 'landmark' => '', 'phone' => '01112345678', 'expected_total' => '1.00']))
        ->assertUnprocessable()->json('errors');

    expect(collect($errors)->pluck('field')->all())->toContain('source', 'landmark', 'phone');
    expect(Order::query()->count())->toBe(0);
});

it('creates one order for a double submit with the same Idempotency-Key', function (): void {
    Support::actingAs(Role::OrderOperator);
    $variant = CartTestKit::variant('500.00', 5);
    $body = Support::body([$variant->id => 1], ['expected_total' => adminQuote(Support::body([$variant->id => 1]))->json('grand_total')]);
    $key = (string) Str::uuid();

    $first = adminCreate($body, $key)->assertCreated()->json('id');
    $second = adminCreate($body, $key)->assertCreated()->json('id');

    expect($second)->toBe($first)->and(Order::query()->count())->toBe(1);
    test()->postJson('/api/v1/admin/orders', $body)->assertStatus(400);
});

it('refuses with price-changed when the total moved since the quote, and writes nothing', function (): void {
    Support::actingAs(Role::OrderOperator);
    $variant = CartTestKit::variant('500.00', 5);

    adminCreate(Support::body([$variant->id => 1], ['expected_total' => '1.00']))
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/price-changed')
        ->assertJsonPath('errors.0.field', 'expected_total');

    expect(Order::query()->count())->toBe(0)
        ->and(App\Domain\Cart\Models\Cart::query()->count())->toBe(0)
        ->and(StockLevel::query()->where('variant_id', $variant->id)->value('reserved'))->toBe(0);
});

it('refuses with insufficient-stock per line and leaves no staff cart behind', function (): void {
    Support::actingAs(Role::OrderOperator);
    $variant = CartTestKit::variant('500.00', 1);
    $total = adminQuote(Support::body([$variant->id => 3]))->json('grand_total');

    adminCreate(Support::body([$variant->id => 3], ['expected_total' => $total]))
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/insufficient-stock')
        ->assertJsonPath('errors.0.field', 'lines.'.$variant->id);

    expect(App\Domain\Cart\Models\Cart::query()->count())->toBe(0);
});

it('applies a coupon through the pipeline and records its usage', function (): void {
    Support::actingAs(Role::OrderOperator);
    $variant = CartTestKit::variant('1000.00', 5);
    Coupon::factory()->percent('10')->create(['code' => 'EID2026']);

    $quote = adminQuote(Support::body([$variant->id => 2], ['coupon_code' => 'eid2026']))->assertOk()->json();
    expect($quote['coupon'])->toMatchArray(['code' => 'EID2026', 'applied' => true, 'discount' => '200.00']);

    $id = adminCreate(Support::body([$variant->id => 2], ['coupon_code' => 'eid2026', 'expected_total' => $quote['grand_total']]))->assertCreated()->json('id');
    $order = Order::query()->findOrFail($id);
    expect($order->coupon_code)->toBe('EID2026')->and((string) $order->coupon_discount)->toBe('200.00')
        ->and(App\Domain\Marketing\Models\CouponUsage::query()->where('order_id', $id)->count())->toBe(1);

    adminQuote(Support::body([$variant->id => 1], ['coupon_code' => 'NOPE']))->assertOk()->assertJsonPath('ready', false)->assertJsonPath('blockers.0.field', 'coupon_code');
});

it('looks a customer up by phone with saved addresses and open orders', function (): void {
    Support::actingAs(Role::OrderOperator);
    $variant = CartTestKit::variant('500.00', 5);
    $placed = app(App\Domain\Ordering\Actions\PlaceOrder::class)->handle(CheckoutKit::data(CheckoutKit::cart([$variant->id => 1])));

    $json = $this->getJson('/api/v1/admin/orders/compose/customer?phone=01712345678')->assertOk()->json();
    expect($json['phone'])->toBe('+8801712345678')
        ->and($json['customer']['name'])->toBe('রাহেলা খাতুন')
        ->and($json['addresses'][0]['landmark'])->toBe('জামে মসজিদের পাশে')
        ->and($json['open_orders'][0]['number'])->toBe($placed->number);

    $this->getJson('/api/v1/admin/orders/compose/customer?phone=01812345678')->assertOk()->assertJsonPath('customer', null);
    $this->getJson('/api/v1/admin/orders/compose/customer?phone=01112345678')->assertUnprocessable();
});

it('searches sellable variants by name, Bangla name and SKU with available stock', function (): void {
    Support::actingAs(Role::OrderOperator);
    $variant = CartTestKit::variant('750.00', 4);

    $json = $this->getJson('/api/v1/admin/orders/compose/variants?q=Remote')->assertOk()->json('data');
    expect($json[0])->toMatchArray(['variant_id' => $variant->id, 'price' => '750.00', 'available' => 4]);
    expect($this->getJson('/api/v1/admin/orders/compose/variants?q='.urlencode('রিমোট'))->json('data.0.variant_id'))->toBe($variant->id);
    expect($this->getJson('/api/v1/admin/orders/compose/variants?q='.$variant->sku)->json('data.0.variant_id'))->toBe($variant->id);
});

describe('role matrix', function (): void {
    it('guards every create-order endpoint with orders.create', function (string $method, string $path): void {
        RoleMatrix::assert($this, Permission::OrdersCreate, function () use ($method, $path) {
            $variant = CartTestKit::variant('500.00', 5);
            $body = Support::body([$variant->id => 1]);

            return match ($path) {
                'store' => $this->postJson('/api/v1/admin/orders', $body + ['expected_total' => CheckoutKit::total(CheckoutKit::cart([$variant->id => 1]))], ['Idempotency-Key' => (string) Str::uuid()]),
                'quote' => $this->postJson('/api/v1/admin/orders/quote', $body),
                default => $this->{$method}($path),
            };
        }, $path === 'store' ? 201 : 200);
    })->with([
        'store' => ['postJson', 'store'],
        'quote' => ['postJson', 'quote'],
        'customer' => ['getJson', '/api/v1/admin/orders/compose/customer?phone=01712345678'],
        'variants' => ['getJson', '/api/v1/admin/orders/compose/variants?q=Remote'],
        'districts' => ['getJson', '/api/v1/admin/orders/compose/districts'],
        'upazilas' => ['getJson', '/api/v1/admin/orders/compose/districts/8/upazilas'],
    ]);

    it('refuses a guest with 401', function (): void {
        $this->postJson('/api/v1/admin/orders/quote', ['lines' => []])->assertUnauthorized();
        $this->getJson('/api/v1/admin/orders/compose/districts')->assertUnauthorized();
    });
});
