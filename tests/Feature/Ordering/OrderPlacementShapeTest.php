<?php

declare(strict_types=1);

use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Ordering\Enums\OrderEventType;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Exceptions\OrderViolation;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Services\OrderNumberGenerator;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Ordering\Support\OrderFixtures;

/*
 * P4-ORD-01 — the schema and the order shape PlaceOrder (batch 5) will produce, built
 * here through the same services by OrderFixtures.
 */

it('creates every table and index the design lists', function (): void {
    foreach (['customers', 'addresses', 'orders', 'order_items', 'order_events', 'order_financials', 'payments', 'refunds', 'refund_items', 'order_number_sequences'] as $table) {
        expect(DB::getSchemaBuilder()->hasTable($table))->toBeTrue($table);
    }

    $indexes = collect(DB::getSchemaBuilder()->getIndexes('orders'))->map(fn (array $i): string => implode(',', $i['columns']));
    foreach (['number', 'status,created_at', 'payment_status', 'customer_id,created_at', 'shipping_phone', 'risk_level,status', 'delivered_at', 'created_at'] as $columns) {
        expect($indexes)->toContain($columns);
    }
    $items = collect(DB::getSchemaBuilder()->getIndexes('order_items'))->map(fn (array $i): string => implode(',', $i['columns']));
    expect($items)->toContain('order_id')->toContain('variant_id,created_at')->toContain('product_id');
    $customers = collect(DB::getSchemaBuilder()->getIndexes('customers'))->map(fn (array $i): string => implode(',', $i['columns']));
    expect($customers)->toContain('phone')->toContain('total_spent')->toContain('last_order_at')->toContain('orders_count');
});

it('numbers orders TMB-YY-NNNNN per Dhaka year', function (): void {
    $numbers = DB::transaction(fn (): array => [
        app(OrderNumberGenerator::class)->next(CarbonImmutable::parse('2026-06-01 10:00', 'UTC')),
        app(OrderNumberGenerator::class)->next(CarbonImmutable::parse('2026-06-01 10:01', 'UTC')),
        // 31 Dec 19:00 UTC is already 1 Jan in Dhaka (UTC+6): a new year, a new counter.
        app(OrderNumberGenerator::class)->next(CarbonImmutable::parse('2026-12-31 19:00', 'UTC')),
    ]);

    expect($numbers)->toBe(['TMB-26-00001', 'TMB-26-00002', 'TMB-27-00001'])
        ->and(OrderNumberGenerator::format(2026, 123456))->toBe('TMB-26-123456');
});

it('places an order in the shape checkout will: snapshots, reservation, pending payment, placed event', function (): void {
    Event::fake([OrderPlaced::class]);
    $variant = OrderFixtures::stockedVariant(10, price: '1250.00', cost: '812.50');

    $order = OrderFixtures::place([$variant->id => 2]);
    $item = $order->items()->sole();

    expect($order->number)->toMatch('/^TMB-\d{2}-\d{5}$/')
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->payment_status)->toBe(OrderPaymentStatus::Unpaid)
        ->and((string) $order->grand_total)->toBe('2570.00')
        // §7.3
        ->and($order->grand_total->equals($order->subtotal->minus($order->discount_total)->plus($order->shipping_charge)->plus($order->tax_total)))->toBeTrue()
        ->and($item->cost_price_snapshot)->toBe('812.5000')
        ->and((string) $item->cogs())->toBe('1625.00')
        ->and($item->sku)->toBe($variant->sku)
        ->and(StockLevel::query()->where('variant_id', $variant->id)->value('reserved'))->toBe(2)
        ->and($order->payments()->sole()->status)->toBe(PaymentStatus::Pending)
        ->and($order->events()->sole()->type)->toBe(OrderEventType::Placed);
    Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $e): bool => $e->orderId === $order->id);
});

it('never recalculates cost_price_snapshot, even when the variant cost changes', function (): void {
    $variant = OrderFixtures::stockedVariant(10, cost: '800.00');
    $order = OrderFixtures::place([$variant->id => 1]);
    $item = $order->items()->sole();

    $variant->update(['cost_price' => '950.00']);

    expect($item->refresh()->cost_price_snapshot)->toBe('800.0000')
        ->and(fn () => $item->update(['cost_price_snapshot' => '950.0000']))->toThrow(OrderViolation::class)
        ->and(fn () => $item->delete())->toThrow(OrderViolation::class);
    // Other columns stay editable (order edit, P4-ORD-07).
    $item->refresh()->update(['quantity_shipped' => 1]);
    expect($item->refresh()->quantity_shipped)->toBe(1);
});

it('stores money as exact decimals', function (): void {
    $item = OrderItem::factory()->create(['unit_price' => '0.10', 'quantity' => 3, 'line_total' => Money::of('0.10')->times(3)]);

    expect((string) $item->refresh()->line_total)->toBe('0.30');
});

it('masks the shipping phone', function (): void {
    $variant = OrderFixtures::stockedVariant();
    $order = OrderFixtures::place([$variant->id => 1]);

    expect($order->maskedShippingPhone())->toMatch('/^01[3-9]\*{4}\d{4}$/')
        ->and($order->shipping_phone)->toStartWith('+8801');
});
