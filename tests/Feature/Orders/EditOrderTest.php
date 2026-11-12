<?php

declare(strict_types=1);

use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\CouponUsage;
use App\Domain\Ordering\Actions\PlaceOrder;
use App\Domain\Ordering\Enums\OrderEventType;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Events\OrderEdited;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Payment\Models\Payment;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Orders\Support;
use Tests\Support\RoleMatrix;

/*
 * P4-ORD-07 — edit an order after placement: reservation follows the lines (ascending
 * variant-id locks), totals re-quoted by the pipeline, cost snapshots of existing lines
 * never change, every edit audited before/after, only before packing.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    config(['risk.first_order_hold_threshold' => '100000.00']);
});

/** @param  array<int, int>  $lines */
function placeWeb(array $lines, ?string $coupon = null): Order
{
    return app(PlaceOrder::class)->handle(CheckoutKit::data(CheckoutKit::cart($lines, $coupon)));
}

/**
 * @param  array<int, int>  $lines
 * @param  array<string, mixed>  $overrides
 */
function editOrder(Order $order, array $lines, array $overrides = []): Illuminate\Testing\TestResponse
{
    $body = Support::body($lines, $overrides);
    unset($body['source']);
    if (! array_key_exists('expected_total', $overrides)) {
        $body['expected_total'] = test()->postJson("/api/v1/admin/orders/{$order->id}/edit-quote", $body)->assertOk()->json('grand_total');
    }

    return test()->putJson("/api/v1/admin/orders/{$order->id}", $body);
}

function reserved(int $variantId): int
{
    return (int) StockLevel::query()->where('variant_id', $variantId)->value('reserved');
}

it('adjusts the reservation, re-prices, keeps old cost snapshots and snapshots the new line', function (): void {
    $user = Support::actingAs(Role::OrderOperator);
    Event::fake([OrderEdited::class]);
    $a = CartTestKit::variant('1000.00', 10, variant: ['cost_price' => '600.00']);
    $b = CartTestKit::variant('500.00', 10, variant: ['cost_price' => '300.00']);
    $c = CartTestKit::variant('200.00', 10, variant: ['cost_price' => '120.00']);
    $order = placeWeb([$a->id => 2, $b->id => 3]);
    expect(reserved($a->id))->toBe(2)->and(reserved($b->id))->toBe(3);
    $aItem = $order->items()->where('variant_id', $a->id)->sole();

    // Costs move after placement: existing lines must keep theirs, the new line takes today's.
    $a->update(['cost_price' => '999.00']);
    $c->update(['cost_price' => '150.00']);

    $json = editOrder($order, [$a->id => 5, $c->id => 1], ['note' => 'Customer called to change'])->assertOk()->json();

    expect(reserved($a->id))->toBe(5)->and(reserved($b->id))->toBe(0)->and(reserved($c->id))->toBe(1)
        ->and($json['totals']['subtotal'])->toBe('5200.00');

    $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get();
    expect($items)->toHaveCount(3)
        ->and($items[0]->id)->toBe($aItem->id)
        ->and($items[0]->quantity)->toBe(5)
        ->and($items[0]->cost_price_snapshot)->toBe('600.0000')
        // b removed: never deleted, quantity 0
        ->and($items[1]->quantity)->toBe(0)
        ->and((string) $items[1]->line_total)->toBe('0.00')
        ->and($items[1]->cost_price_snapshot)->toBe('300.0000')
        ->and($items[2]->variant_id)->toBe($c->id)
        ->and($items[2]->cost_price_snapshot)->toBe('150.0000');

    $event = OrderEvent::query()->where('order_id', $order->id)->where('type', OrderEventType::Edited)->sole();
    expect($event->user_id)->toBe($user->id)
        ->and($event->description)->toBe('Customer called to change')
        ->and($event->meta['changed'])->toContain('lines', 'totals')
        ->and(collect($event->meta['before']['lines'])->pluck('quantity', 'variant_id')->all())->toBe([$a->id => 2, $b->id => 3])
        ->and(collect($event->meta['after']['lines'])->pluck('quantity', 'variant_id')->all())->toBe([$a->id => 5, $c->id => 1])
        ->and($event->meta['before']['totals']['grand_total'])->toBe($order->grand_total->toDecimal());

    Event::assertDispatched(OrderEdited::class, fn (OrderEdited $e): bool => $e->orderId === $order->id && $e->userId === $user->id);
    Support::assertStockInvariants();
    Support::assertOrderFinancials($order);
});

it('re-quotes the delivery charge for a new address and audits it without storing the street line', function (): void {
    Support::actingAs(Role::Manager);
    $a = CartTestKit::variant('1000.00', 10);
    $order = placeWeb([$a->id => 1]);
    $outside = $order->shipping_charge->toDecimal();

    // Dhaka (district 1? use the city option): find a Dhaka-metro district from the seeded data.
    $dhaka = App\Domain\Customer\Models\District::query()->where('name', 'Dhaka')->firstOrFail();
    $json = editOrder($order, [$a->id => 1], ['district_id' => $dhaka->id, 'upazila_id' => null, 'address_line' => 'House 14, Road 3, Block C', 'landmark' => 'Opposite Mirpur 10 metro'])
        ->assertOk()->json();

    expect($json['totals']['shipping_charge'])->not->toBe($outside)
        ->and($json['shipping']['district'])->toBe('Dhaka');

    $event = OrderEvent::query()->where('order_id', $order->id)->where('type', OrderEventType::Edited)->sole();
    expect($event->meta['changed'])->toContain('address', 'totals')
        ->and($event->meta['before']['location']['district'])->not->toBe('Dhaka')
        ->and($event->meta['after']['location']['district'])->toBe('Dhaka')
        ->and(json_encode($event->meta, JSON_UNESCAPED_UNICODE))->not->toContain('House 14')->not->toContain('Mirpur 10 metro')->not->toContain('8801712345678');

    expect(Payment::query()->where('order_id', $order->id)->sole()->amount->toDecimal())->toBe($json['totals']['grand_total']);
    Support::assertOrderFinancials($order);
});

it('refuses an increase beyond stock with insufficient-stock and changes nothing', function (): void {
    Support::actingAs(Role::OrderOperator);
    $a = CartTestKit::variant('1000.00', 3);
    $order = placeWeb([$a->id => 2]);
    $before = $order->refresh()->grand_total->toDecimal();

    editOrder($order, [$a->id => 4])->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/insufficient-stock');

    expect(reserved($a->id))->toBe(2)
        ->and($order->refresh()->grand_total->toDecimal())->toBe($before)
        ->and(OrderEvent::query()->where('type', OrderEventType::Edited)->count())->toBe(0);
    Support::assertStockInvariants();
});

it('refuses a stale quote with price-changed', function (): void {
    Support::actingAs(Role::OrderOperator);
    $a = CartTestKit::variant('1000.00', 5);
    $order = placeWeb([$a->id => 1]);

    editOrder($order, [$a->id => 2], ['expected_total' => '1.00'])->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/price-changed');
    expect(reserved($a->id))->toBe(1);
});

it('is editable while pending, on hold, confirmed or processing — not once packed or shipped', function (OrderStatus $status, bool $editable): void {
    Support::actingAs(Role::Manager);
    $a = CartTestKit::variant('1000.00', 5);
    $order = placeWeb([$a->id => 1]);
    $path = match ($status) {
        OrderStatus::Pending => [],
        OrderStatus::OnHold => [OrderStatus::OnHold],
        OrderStatus::Confirmed => [OrderStatus::Confirmed],
        OrderStatus::Processing => [OrderStatus::Confirmed, OrderStatus::Processing],
        OrderStatus::ReadyToShip => [OrderStatus::Confirmed, OrderStatus::ReadyToShip],
        OrderStatus::Cancelled => [OrderStatus::Cancelled],
        default => [],
    };
    foreach ($path as $to) {
        $to === OrderStatus::Cancelled
            ? app(App\Domain\Ordering\Actions\CancelOrder::class)->handle($order, App\Domain\Ordering\Enums\CancellationReason::CustomerRequest)
            : app(App\Domain\Ordering\Actions\TransitionOrder::class)->handle($order, $to);
    }

    expect($this->getJson("/api/v1/admin/orders/{$order->id}")->json('editable'))->toBe($editable);
    $body = Support::body([$a->id => 2]);
    unset($body['source']);
    $quote = $this->postJson("/api/v1/admin/orders/{$order->id}/edit-quote", $body);
    if ($editable) {
        $quote->assertOk();
        editOrder($order, [$a->id => 2])->assertOk();
        expect(reserved($a->id))->toBe(2);
    } else {
        $quote->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/conflict');
        $this->putJson("/api/v1/admin/orders/{$order->id}", $body + ['expected_total' => '1000.00'])->assertStatus(409);
    }
    Support::assertStockInvariants();
})->with([
    'pending' => [OrderStatus::Pending, true],
    'on hold' => [OrderStatus::OnHold, true],
    'confirmed' => [OrderStatus::Confirmed, true],
    'processing' => [OrderStatus::Processing, true],
    'ready to ship (packed)' => [OrderStatus::ReadyToShip, false],
    'cancelled' => [OrderStatus::Cancelled, false],
]);

it('keeps the coupon applied and re-records its usage at the new discount', function (): void {
    Support::actingAs(Role::OrderOperator);
    $a = CartTestKit::variant('1000.00', 10);
    Coupon::factory()->percent('10')->create(['code' => 'EID2026', 'usage_limit_per_customer' => 1]);
    $order = placeWeb([$a->id => 2], 'EID2026');
    expect((string) $order->coupon_discount)->toBe('200.00');

    editOrder($order, [$a->id => 3])->assertOk()->assertJsonPath('totals.coupon_discount', '300.00');

    $usage = CouponUsage::query()->where('order_id', $order->id)->sole();
    expect((string) $usage->discount_amount)->toBe('300.00')
        ->and(Coupon::query()->where('code', 'EID2026')->value('used_count'))->toBe(1);
    Support::assertOrderFinancials($order);
});

it('refuses to remove every line', function (): void {
    Support::actingAs(Role::OrderOperator);
    $a = CartTestKit::variant('1000.00', 5);
    $order = placeWeb([$a->id => 1]);
    $body = Support::body([], ['expected_total' => '0.00']);
    unset($body['source']);
    $body['lines'] = [];

    $this->putJson("/api/v1/admin/orders/{$order->id}", $body)->assertUnprocessable();
    expect(reserved($a->id))->toBe(1);
});

it('records nothing for an edit that changes nothing', function (): void {
    Support::actingAs(Role::OrderOperator);
    $a = CartTestKit::variant('1000.00', 5);
    $order = placeWeb([$a->id => 1]);

    editOrder($order, [$a->id => 1], ['name' => 'রাহেলা খাতুন', 'landmark' => 'জামে মসজিদের পাশে', 'address_line' => 'বাড়ি ১২, রোড ৫, ব্লক সি'])->assertOk();

    expect(OrderEvent::query()->where('type', OrderEventType::Edited)->count())->toBe(0);
});

describe('role matrix', function (): void {
    it('guards edit and edit-quote with orders.update', function (string $endpoint): void {
        RoleMatrix::assert($this, Permission::OrdersUpdate, function () use ($endpoint) {
            $a = CartTestKit::variant('1000.00', 5);
            $order = placeWeb([$a->id => 1]);
            $body = Support::body([$a->id => 2]);
            unset($body['source']);
            $body['expected_total'] = app(App\Domain\Ordering\Services\OrderQuotes::class)
                ->forEdit($order, [$a->id => 2], App\Domain\Customer\Models\District::query()->findOrFail(CheckoutKit::DISTRICT), App\Domain\Customer\Models\Upazila::query()->findOrFail(CheckoutKit::UPAZILA))
                ->grandTotal->toDecimal();

            return $endpoint === 'update'
                ? $this->putJson("/api/v1/admin/orders/{$order->id}", $body)
                : $this->postJson("/api/v1/admin/orders/{$order->id}/edit-quote", $body);
        });
    })->with(['update', 'quote']);
});
