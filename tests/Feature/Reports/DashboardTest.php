<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType as T;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\StockOverview;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\VerificationStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Models\Enums\Role;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finance\FinanceKit;

/*
 * Admin dashboard (P8, 04-admin-spec B1): ledger agreement to the poisha over a seeded
 * month, Dhaka-date bucketing at the 18:00 UTC edge, previous-period deltas, the per-role
 * block visibility, PII masking, the 403 and the 400s.
 */

function dashboardUser(?Role $role): User
{
    (new RolesAndPermissionsSeeder)->run();
    app('auth')->forgetGuards();
    $factory = User::factory();
    $user = ($role === null ? $factory : $factory->withRole($role))->create()->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

/** An order placed at an exact UTC instant, with one line. */
function dashboardOrder(string $placedUtc, string $total, OrderStatus $status = OrderStatus::Pending, int $qty = 1, ?Customer $customer = null, ?Product $product = null): Order
{
    $order = Order::factory()->status($status)->total($total)->create([
        'placed_at' => CarbonImmutable::parse($placedUtc, 'UTC'),
        'customer_id' => ($customer ?? Customer::factory()->create())->id,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product?->id,
        'unit_price' => bcdiv($total, (string) $qty, 2),
        'quantity' => $qty,
        'discount' => '0.00',
        'line_total' => $total,
        'cost_price_snapshot' => '100.0000',
    ]);

    return $order->refresh();
}

function dashboardLedger(T $type, Direction $direction, string $amount, string $on, string $sourceType, int $sourceId, bool $recognised = true, bool $cash = false): void
{
    DB::transaction(fn () => app(Ledger::class)->record(new LedgerEntry(
        type: $type,
        direction: $direction,
        amount: Money::of($amount),
        occurredOn: CarbonImmutable::createFromFormat('!Y-m-d', $on, 'Asia/Dhaka'),
        sourceType: $sourceType,
        sourceId: $sourceId,
        isCashMovement: $cash,
        isRecognisedRevenue: $recognised,
    )));
}

/** Σ in − Σ out straight from financial_transactions — the reference the dashboard must equal. */
function ledgerNet(string $from, string $to, array $types, ?bool $recognised = true, ?bool $cash = null): string
{
    $rows = DB::table('financial_transactions')
        ->whereBetween('occurred_on', [$from, $to])
        ->whereIn('type', array_map(static fn (T $t): string => $t->value, $types))
        ->when($recognised !== null, static fn ($q) => $q->where('is_recognised_revenue', $recognised))
        ->when($cash !== null, static fn ($q) => $q->where('is_cash_movement', $cash))
        ->get(['direction', 'amount']);

    $net = Money::zero();
    foreach ($rows as $row) {
        $amount = Money::of(sprintf('%.2F', round((float) $row->amount, 2)));
        $net = $row->direction === 'in' ? $net->plus($amount) : $net->minus($amount);
    }

    return $net->toDecimal();
}

function sumSparkline(array $points): string
{
    $total = Money::zero();
    foreach ($points as $point) {
        $total = $total->plus(Money::of($point['value']));
    }

    return $total->toDecimal();
}

it('matches the ledger to the poisha over a seeded month', function (): void {
    dashboardUser(Role::Manager);

    // Ten delivered COD orders across October 2026 at odd poisha amounts, delivered through
    // the real state machine so RecognizeOrderRevenue writes the ledger.
    $days = ['2026-10-01', '2026-10-03', '2026-10-07', '2026-10-11', '2026-10-15', '2026-10-18', '2026-10-22', '2026-10-26', '2026-10-30', '2026-10-31',
        // Just outside the month on either side.
        '2026-09-30', '2026-11-01'];
    foreach ($days as $i => $day) {
        $this->travelTo(CarbonImmutable::parse("{$day} 08:00:00", 'Asia/Dhaka'));
        $order = Order::factory()->status(OrderStatus::Shipped)->create([
            'subtotal' => '1250.35',
            'discount_total' => $i % 3 === 0 ? '50.10' : '0.00',
            'coupon_discount' => $i % 3 === 0 ? '50.10' : '0.00',
            'shipping_charge' => '70.00',
            'grand_total' => $i % 3 === 0 ? '1270.25' : '1320.35',
            'due_total' => $i % 3 === 0 ? '1270.25' : '1320.35',
            'placed_at' => now()->subDay(),
            'shipped_at' => now(),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id, 'unit_price' => '1250.35', 'quantity' => 1, 'discount' => '0.00',
            'line_total' => '1250.35', 'cost_price_snapshot' => '611.3333',
        ]);
        FinanceKit::deliver($order->refresh());
        dashboardLedger(T::CourierCharge, Direction::Out, '61.57', $day, 'shipment', 1000 + $i, cash: true);
        if ($i % 2 === 0) {
            dashboardLedger(T::CodReceived, Direction::In, '1190.01', $day, 'manual_cod_receipt', 2000 + $i, recognised: false, cash: true);
        }
    }
    // A period cost, a refund, and entries just outside the month.
    dashboardLedger(T::Marketing, Direction::Out, '999.99', '2026-10-20', 'expense', 1, cash: true);
    dashboardLedger(T::Refund, Direction::Out, '123.45', '2026-10-21', 'refund', 1, cash: true);
    dashboardLedger(T::CodReceived, Direction::In, '3333.33', '2026-11-01', 'manual_cod_receipt', 9997, recognised: false, cash: true);

    $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00:00', 'Asia/Dhaka'));
    $kpis = $this->getJson('/api/v1/admin/dashboard?range=custom&from=2026-10-01&to=2026-10-31')->assertOk()->json('kpis');

    $revenueTypes = [T::SaleRevenue, T::ShippingRevenue, T::DiscountGiven];
    $grossTypes = [...$revenueTypes, T::Cogs];
    $allPnl = [...$grossTypes, T::CourierCharge, T::CourierCodFee, T::CourierReturnCharge, T::GatewayFee, T::Packaging, T::Refund, T::InventoryWriteOff, T::Marketing, T::Salary, T::Rent, T::Utility, T::OtherExpense, T::OtherIncome];

    expect($kpis['recognised_revenue']['value'])->toBe(ledgerNet('2026-10-01', '2026-10-31', $revenueTypes))
        ->and($kpis['recognised_revenue']['value'])->toBe('13003.10') // 10 × (1250.35 + 70) − 4 × 50.10 (orders 0, 3, 6, 9)
        ->and($kpis['gross_profit']['value'])->toBe(ledgerNet('2026-10-01', '2026-10-31', $grossTypes))
        ->and($kpis['net_profit']['value'])->toBe(ledgerNet('2026-10-01', '2026-10-31', $allPnl))
        ->and($kpis['cash_collected']['value'])->toBe(ledgerNet('2026-10-01', '2026-10-31', [T::CodReceived], null, true))
        ->and($kpis['cash_collected']['value'])->toBe('5950.05')
        // Each sparkline sums back to its total — no poisha lost to bucketing.
        ->and(sumSparkline($kpis['recognised_revenue']['sparkline']))->toBe($kpis['recognised_revenue']['value'])
        ->and(sumSparkline($kpis['gross_profit']['sparkline']))->toBe($kpis['gross_profit']['value'])
        ->and(sumSparkline($kpis['net_profit']['sparkline']))->toBe($kpis['net_profit']['value'])
        ->and(sumSparkline($kpis['cash_collected']['sparkline']))->toBe($kpis['cash_collected']['value'])
        // The comparison range is September; only the 30 Sep delivery falls in it.
        ->and($kpis['recognised_revenue']['previous'])->toBe('1320.35');

    FinanceKit::assertFinanceInvariant();
});

it('buckets placed orders by Dhaka date at the 18:00 UTC edge', function (): void {
    dashboardUser(Role::Manager);
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'Asia/Dhaka'));

    dashboardOrder('2026-11-04 17:59:59', '100.00'); // 23:59:59 Dhaka, 4 Nov
    dashboardOrder('2026-11-04 18:00:00', '200.00'); // 00:00 Dhaka, 5 Nov
    dashboardOrder('2026-11-05 00:00:00', '400.00'); // 06:00 Dhaka, 5 Nov (00:00 UTC is not a day edge)
    dashboardOrder('2026-11-01 17:59:59', '800.00'); // 23:59:59 Dhaka, 1 Nov — before the range

    $body = $this->getJson('/api/v1/admin/dashboard?range=7d')->assertOk()->json();
    $byDay = collect($body['kpis']['orders_placed']['sparkline'])->keyBy('key');

    expect($body['range'])->toMatchArray(['preset' => '7d', 'from' => '2026-11-02', 'to' => '2026-11-08', 'granularity' => 'day'])
        ->and($byDay['2026-11-04'])->toBe(['key' => '2026-11-04', 'count' => 1, 'value' => '100.00'])
        ->and($byDay['2026-11-05'])->toBe(['key' => '2026-11-05', 'count' => 2, 'value' => '600.00'])
        ->and($body['kpis']['orders_placed']['count'])->toBe(3)
        ->and($body['kpis']['orders_placed']['value'])->toBe('700.00')
        ->and($body['kpis']['orders_placed']['previous_count'])->toBe(1)
        ->and($body['kpis']['orders_placed']['previous_value'])->toBe('800.00');

    // A one-day custom range picks exactly the Dhaka day.
    $day = $this->getJson('/api/v1/admin/dashboard?range=custom&from=2026-11-05&to=2026-11-05')->assertOk()->json('kpis.orders_placed');
    expect($day['count'])->toBe(2)->and($day['value'])->toBe('600.00');
});

it('computes previous-period deltas and AOV on the server', function (): void {
    dashboardUser(Role::Manager);
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'Asia/Dhaka'));

    $returning = Customer::factory()->create();
    // Previous period (26 Oct – 1 Nov): 2 orders, ৳1,000.
    dashboardOrder('2026-10-27 06:00:00', '400.00', customer: $returning);
    dashboardOrder('2026-10-30 06:00:00', '600.00');
    // This period (2 – 8 Nov): 4 orders, ৳2,500.50 — one cancelled (still placed), one from a returning customer.
    dashboardOrder('2026-11-02 06:00:00', '1000.00', customer: $returning);
    dashboardOrder('2026-11-03 06:00:00', '500.25');
    dashboardOrder('2026-11-06 06:00:00', '500.25', OrderStatus::Cancelled);
    dashboardOrder('2026-11-07 06:00:00', '500.00');

    $kpis = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('kpis');

    expect($kpis['orders_placed'])->toMatchArray([
        'count' => 4, 'previous_count' => 2, 'count_delta_percent' => '100.00',
        'value' => '2500.50', 'previous_value' => '1000.00', 'value_delta_percent' => '150.05',
    ])
        ->and($kpis['average_order_value'])->toMatchArray(['value' => '625.13', 'previous' => '500.00', 'delta_percent' => '25.03'])
        ->and($kpis['customers'])->toMatchArray(['customers' => 4, 'new' => 3, 'returning' => 1, 'previous_new' => 2, 'previous_returning' => 0, 'new_delta_percent' => '50.00']);
});

it('compares this_month with the same days of the month before', function (): void {
    dashboardUser(Role::Manager);
    $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00:00', 'Asia/Dhaka'));

    $body = $this->getJson('/api/v1/admin/dashboard?range=this_month')->assertOk()->json();

    expect($body['range'])->toMatchArray(['preset' => 'this_month', 'from' => '2026-11-01', 'to' => '2026-11-13'])
        ->and($body['previous'])->toBe(['from' => '2026-10-01', 'to' => '2026-10-13', 'days' => 13]);
});

it('returns 403 without dashboard.view and 401 for a guest', function (): void {
    $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();

    dashboardUser(null);
    $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
});

it('answers every launch role, which all hold dashboard.view', function (Role $role): void {
    dashboardUser($role);
    $this->getJson('/api/v1/admin/dashboard')->assertOk();
})->with(Role::cases());

it('shows each block only to roles that can see the screen behind it', function (Role $role, bool $orders, bool $finance, bool $customers, bool $inventory): void {
    dashboardUser($role);

    $body = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json();

    expect($body['visibility'])->toBe(['orders' => $orders, 'finance' => $finance, 'customers' => $customers, 'inventory' => $inventory])
        ->and($body['kpis']['orders_placed'] !== null)->toBe($orders)
        ->and($body['status'] !== null)->toBe($orders)
        ->and($body['activity'] !== null)->toBe($orders)
        ->and($body['kpis']['recognised_revenue'] !== null)->toBe($finance)
        ->and($body['kpis']['net_profit'] !== null)->toBe($finance)
        ->and($body['kpis']['cash_collected'] !== null)->toBe($finance)
        ->and($body['categories'] !== null)->toBe($finance)
        ->and($body['kpis']['customers'] !== null)->toBe($customers)
        ->and($body['low_stock'] !== null)->toBe($inventory);

    if ($orders) {
        // The trend's revenue line follows finance.view too.
        expect(collect($body['trend']['buckets'])->every(fn (array $b): bool => ($b['recognised_revenue'] !== null) === $finance))->toBeTrue();
    }
})->with([
    'super_admin' => [Role::SuperAdmin, true, true, true, true],
    'manager' => [Role::Manager, true, true, true, true],
    'order_operator' => [Role::OrderOperator, true, false, true, true],
    'inventory_staff' => [Role::InventoryStaff, true, false, false, true],
    'accountant' => [Role::Accountant, true, true, true, true],
    'content_editor' => [Role::ContentEditor, false, false, false, false],
    'viewer' => [Role::Viewer, true, false, true, true],
]);

it('lists only the attention queues the viewer can work', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'Asia/Dhaka'));
    $held = dashboardOrder('2026-11-08 02:00:00', '6260.00', OrderStatus::OnHold);
    $held->forceFill(['verification_status' => VerificationStatus::Pending])->save();

    dashboardUser(Role::OrderOperator);
    $operator = collect($this->getJson('/api/v1/admin/dashboard')->assertOk()->json('attention'))->keyBy('key');
    expect($operator->keys()->all())->toEqualCanonicalizing(['verification', 'failed_bookings', 'unmapped_areas', 'submitted', 'low_stock'])
        ->and($operator['verification']['count'])->toBe(1)
        ->and($operator['verification']['oldest_at'])->toBe('2026-11-08T02:00:00Z');

    dashboardUser(Role::Accountant);
    $accountant = collect($this->getJson('/api/v1/admin/dashboard')->assertOk()->json('attention'))->pluck('key')->all();
    expect($accountant)->toEqualCanonicalizing(['failed_bookings', 'unmapped_areas', 'submitted', 'cod_variances', 'expenses', 'refunds', 'low_stock']); // no risk.view

    dashboardUser(Role::ContentEditor);
    expect($this->getJson('/api/v1/admin/dashboard')->assertOk()->json('attention'))->toBe([]);
});

it('never returns customer PII, notes or event descriptions', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'Asia/Dhaka'));
    $customer = Customer::factory()->create(['name' => 'Shahriar Kabir', 'phone' => '+8801712345318', 'email' => 'shahriar@example.com']);
    $order = dashboardOrder('2026-11-07 14:08:00', '6260.00', OrderStatus::OnHold, customer: $customer);
    $order->forceFill(['verification_status' => VerificationStatus::Pending, 'shipping_address' => 'House 7, Road 3, Mirpur 10'])->save();
    $staff = dashboardUser(Role::Manager);
    DB::table('order_events')->insert([
        ['order_id' => $order->id, 'type' => 'note', 'field' => null, 'from_status' => null, 'to_status' => null, 'description' => 'Customer said call 01712345318 at House 7', 'user_id' => $staff->id, 'created_at' => now()],
        ['order_id' => $order->id, 'type' => 'status_changed', 'field' => 'status', 'from_status' => 'pending', 'to_status' => 'on_hold', 'description' => 'Held for Shahriar Kabir', 'user_id' => $staff->id, 'created_at' => now()],
    ]);

    $response = $this->getJson('/api/v1/admin/dashboard')->assertOk();
    $raw = $response->getContent();

    expect($raw)->not->toContain('01712345318')
        ->and($raw)->not->toContain('8801712345318')
        ->and($raw)->not->toContain('Shahriar')
        ->and($raw)->not->toContain('shahriar@example.com')
        ->and($raw)->not->toContain('House 7')
        ->and($raw)->not->toContain('Mirpur 10');

    $activity = $response->json('activity');
    expect($activity)->toHaveCount(2)
        ->and(array_keys($activity[0]))->toEqualCanonicalizing(['id', 'type', 'field', 'from', 'to', 'order_id', 'order_number', 'actor', 'at'])
        ->and($activity[0])->toMatchArray(['type' => 'status_changed', 'from' => 'pending', 'to' => 'on_hold', 'order_number' => $order->number, 'actor' => $staff->name]);
});

it('reports low stock by the inventory overview rule', function (): void {
    dashboardUser(Role::InventoryStaff);
    $location = StockLocation::defaultId();
    $product = Product::factory()->create(['name' => 'Wooden Alphabet Puzzle BN+EN']);
    $low = ProductVariant::factory()->create(['product_id' => $product->id]);
    $fine = ProductVariant::factory()->create(['product_id' => $product->id]);
    $out = ProductVariant::factory()->create(['product_id' => $product->id]);
    DB::table('stock_levels')->insert([
        ['variant_id' => $low->id, 'location_id' => $location, 'on_hand' => 8, 'reserved' => 2, 'reorder_point' => 12, 'created_at' => now(), 'updated_at' => now()],
        ['variant_id' => $fine->id, 'location_id' => $location, 'on_hand' => 50, 'reserved' => 0, 'reorder_point' => 5, 'created_at' => now(), 'updated_at' => now()],
        ['variant_id' => $out->id, 'location_id' => $location, 'on_hand' => 1, 'reserved' => 1, 'reorder_point' => 3, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $body = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json();

    // The same numbers as the Inventory overview's Low / Out tabs (a factory product may add
    // its own stock-less variant, which is out).
    $overview = app(StockOverview::class)->summary($location)['counts'];
    expect($body['kpis']['low_stock'])->toBe(['low' => 1, 'out' => $overview['out']])
        ->and($overview['low'])->toBe(1)
        ->and($overview['out'])->toBeGreaterThanOrEqual(1)
        ->and($body['low_stock']['rows'])->toHaveCount(1)
        ->and($body['low_stock']['rows'][0])->toMatchArray(['variant_id' => $low->id, 'name' => 'Wooden Alphabet Puzzle BN+EN', 'on_hand' => 8, 'reserved' => 2, 'available' => 6, 'reorder_point' => 12]);
});

it('ranks best sellers by units over placed, non-cancelled orders', function (): void {
    dashboardUser(Role::Manager);
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'Asia/Dhaka'));
    $bricks = Product::factory()->create(['name' => 'Brick Box 500']);
    $trike = Product::factory()->create(['name' => 'Kids Tricycle with Push Handle']);

    dashboardOrder('2026-11-03 06:00:00', '5550.00', qty: 3, product: $bricks);
    dashboardOrder('2026-11-04 06:00:00', '9800.00', qty: 2, product: $trike);
    dashboardOrder('2026-11-05 06:00:00', '9800.00', OrderStatus::Cancelled, qty: 2, product: $trike);

    $best = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('best_sellers');

    expect($best['units'])->toBe(5)->and($best['products'])->toBe(2)->and($best['value'])->toBe('15350.00')
        ->and($best['rows'][0])->toMatchArray(['product_id' => $bricks->id, 'units' => 3, 'value' => '5550.00'])
        ->and($best['rows'][1])->toMatchArray(['product_id' => $trike->id, 'units' => 2, 'value' => '9800.00']);
});

it('refuses unlisted parameters, presets and ambiguous dates with a 400', function (string $query): void {
    dashboardUser(Role::Manager);
    $this->getJson('/api/v1/admin/dashboard?'.$query)->assertStatus(400)->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, 'bad-request'));
})->with([
    'unknown parameter' => ['range=7d&sort=-value'],
    'unlisted preset' => ['range=90d'],
    'dates with a preset' => ['range=7d&from=2026-11-01'],
    'custom without to' => ['range=custom&from=2026-11-01'],
    'impossible date' => ['range=custom&from=2026-02-30&to=2026-03-01'],
    'reversed range' => ['range=custom&from=2026-11-05&to=2026-11-01'],
    'array value' => ['range[]=7d'],
]);

it('rolls recognised item revenue up to top-level categories', function (): void {
    dashboardUser(Role::Accountant);
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'Asia/Dhaka'));
    $rideOns = Category::factory()->create(['name' => 'Ride-ons & Outdoor']);
    $trikes = Category::factory()->childOf($rideOns)->create(['name' => 'Tricycles']);
    $dolls = Category::factory()->create(['name' => 'Dolls & Figures']);
    $trike = Product::factory()->create(['primary_category_id' => $trikes->id]);
    $doll = Product::factory()->create(['primary_category_id' => $dolls->id]);

    foreach ([[$trike, '9800.00'], [$doll, '4700.00'], [$trike, '0.50']] as [$product, $total]) {
        $order = dashboardOrder('2026-11-03 06:00:00', $total, OrderStatus::Delivered, product: $product);
        $order->forceFill(['delivered_at' => CarbonImmutable::parse('2026-11-05 06:00:00', 'UTC')])->save();
    }

    $categories = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('categories');

    expect($categories['total'])->toBe('14500.50')
        ->and($categories['slices'])->toHaveCount(2)
        ->and($categories['slices'][0])->toMatchArray(['category_id' => $rideOns->id, 'name' => 'Ride-ons & Outdoor', 'amount' => '9800.50', 'share_percent' => '67.59', 'other' => false])
        ->and($categories['slices'][1])->toMatchArray(['category_id' => $dolls->id, 'amount' => '4700.00', 'share_percent' => '32.41']);
});
