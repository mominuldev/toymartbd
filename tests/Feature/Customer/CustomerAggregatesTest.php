<?php

declare(strict_types=1);

use App\Domain\Customer\Actions\RebuildCustomerAggregates;
use App\Domain\Customer\Models\Address;
use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Ordering\Support\OrderFixtures;

/*
 * P4-ORD-10 — the customer aggregate projection: kept current by the listener, rebuildable
 * from orders, and equal to an independent recomputation.
 */

/**
 * The independent recomputation the projection must equal — written from the definitions
 * on CustomerOrderStats, deliberately not sharing code with the implementation.
 *
 * @return array{orders_count: int, delivered_orders_count: int, total_spent: string, avg_order_value: string, first: ?string, last: ?string}
 */
function custRecompute(int $customerId): array
{
    $orders = DB::table('orders')->where('customer_id', $customerId)->get();
    $delivered = $orders->whereIn('status', ['delivered', 'completed', 'partially_returned']);
    $spent = '0.00';
    foreach ($delivered as $o) {
        $spent = bcadd($spent, bcsub((string) $o->grand_total, (string) $o->refunded_total, 2), 2);
    }
    $count = $delivered->count();
    $placed = $orders->pluck('placed_at')->filter()->map(fn ($t): string => (string) Illuminate\Support\Carbon::parse($t));

    return [
        'orders_count' => $orders->where('status', '!=', 'cancelled')->count(),
        'delivered_orders_count' => $count,
        'total_spent' => $spent,
        'avg_order_value' => $count === 0 ? '0.00' : (string) Money::of(bcdiv($spent, (string) $count, 6)),
        'first' => $placed->min(),
        'last' => $placed->max(),
    ];
}

/** @return array{orders_count: int, delivered_orders_count: int, total_spent: string, avg_order_value: string, first: ?string, last: ?string} */
function custStored(Customer $customer): array
{
    $customer->refresh();

    return [
        'orders_count' => $customer->orders_count,
        'delivered_orders_count' => $customer->delivered_orders_count,
        'total_spent' => (string) $customer->total_spent,
        'avg_order_value' => (string) $customer->avg_order_value,
        'first' => $customer->first_order_at === null ? null : (string) $customer->first_order_at,
        'last' => $customer->last_order_at === null ? null : (string) $customer->last_order_at,
    ];
}

it('rebuilds every customer to match an independent recomputation', function (): void {
    $customers = Customer::factory()->count(6)->create();
    $statuses = OrderStatus::cases();
    mt_srand(20261115);

    foreach ($customers->take(5) as $customer) {
        foreach (range(1, mt_rand(1, 6)) as $i) {
            $total = mt_rand(150, 25_000).'.'.str_pad((string) mt_rand(0, 99), 2, '0', STR_PAD_LEFT);
            Order::factory()->for($customer)->total($total)->create([
                'status' => $statuses[array_rand($statuses)],
                'refunded_total' => mt_rand(0, 3) === 0 ? '99.99' : '0.00',
                'placed_at' => now()->subDays(mt_rand(0, 400))->startOfSecond(),
            ]);
        }
    }
    // Stale, wrong values everywhere — the rebuild must overwrite them, not add to them.
    Customer::query()->update(['orders_count' => 99, 'total_spent' => '123.45', 'avg_order_value' => '1.00']);

    $rebuilt = app(RebuildCustomerAggregates::class)->handle(chunk: 2);

    expect($rebuilt)->toBe(6);
    foreach ($customers as $customer) {
        expect(custStored($customer))->toEqual(custRecompute($customer->id));
    }
    // The customer without orders is zeroed.
    expect(custStored($customers->last()))->toMatchArray(['orders_count' => 0, 'total_spent' => '0.00', 'first' => null]);
});

it('runs the rebuild from the console', function (): void {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->total('500.00')->status(OrderStatus::Delivered)->create();
    Customer::query()->update(['total_spent' => '0.00']);

    $this->artisan('toymart:customers:rebuild-aggregates')
        ->expectsOutputToContain('Rebuilt aggregates for 1 customer(s).')
        ->assertSuccessful();

    expect((string) $customer->refresh()->total_spent)->toBe('500.00');
});

it('keeps the projection current as orders are placed, cancelled and delivered', function (): void {
    $customer = Customer::factory()->create();
    $variant = OrderFixtures::stockedVariant(10, price: '1000.00');

    $first = OrderFixtures::place([$variant->id => 1], customer: $customer);
    $second = OrderFixtures::place([$variant->id => 2], customer: $customer);
    expect(custStored($customer))->toEqual(custRecompute($customer->id))
        ->and($customer->orders_count)->toBe(2)
        ->and((string) $customer->total_spent)->toBe('0.00'); // nothing delivered yet

    app(CancelOrder::class)->handle($first, CancellationReason::CustomerRequest);
    expect(custStored($customer))->toEqual(custRecompute($customer->id))
        ->and($customer->orders_count)->toBe(1);

    // Delivery is Fulfilment's Action (Phase 6); drive the state machine directly here.
    DB::transaction(function () use ($second): void {
        $t = app(App\Domain\Ordering\Services\OrderTransitions::class);
        foreach ([OrderStatus::Confirmed, OrderStatus::Shipped, OrderStatus::Delivered] as $to) {
            $t->transition($second, $to);
        }
    });
    expect(custStored($customer))->toEqual(custRecompute($customer->id))
        ->and((string) $customer->total_spent)->toBe('2070.00')
        ->and($customer->delivered_orders_count)->toBe(1);
});

it('counts revenue only on delivery and averages over delivered orders', function (): void {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->total('1000.00')->status(OrderStatus::Delivered)->create();
    Order::factory()->for($customer)->total('2000.01')->status(OrderStatus::Completed)->create();
    Order::factory()->for($customer)->total('5000.00')->status(OrderStatus::Shipped)->create();
    Order::factory()->for($customer)->total('700.00')->status(OrderStatus::Cancelled)->create();

    app(RebuildCustomerAggregates::class)->handle();

    expect(custStored($customer))->toMatchArray([
        'orders_count' => 3,
        'delivered_orders_count' => 2,
        'total_spent' => '3000.01',
        'avg_order_value' => '1500.01', // 1500.005 rounds half away from zero
    ]);
});

it('stores the customer phone E.164 and finds it by any spelling', function (): void {
    $customer = Customer::factory()->create(['phone' => '+8801712345678', 'name' => 'রহিম উদ্দিন']);

    expect(Customer::findByPhone('01712-345678')?->id)->toBe($customer->id)
        ->and(Customer::findByPhone('+880 1712 345678')?->id)->toBe($customer->id)
        ->and(Customer::findByPhone('০১৭১২৩৪৫৬৭৮')?->id)->toBe($customer->id)
        ->and(Customer::findByPhone('01112345678'))->toBeNull()
        ->and($customer->maskedPhone())->toBe('017****5678');
});

it('keeps the landmark on an address', function (): void {
    $address = Address::factory()->create();

    expect($address->landmark)->toBe('জামে মসজিদের পাশে')
        ->and($address->district->name_bn)->toBe('ঢাকা')
        ->and($address->customer)->toBeInstanceOf(Customer::class);
});
