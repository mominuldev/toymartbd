<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Finance\Actions\RecognizeOrderRevenue;
use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Domain\Finance\Reports\Profitability;
use App\Domain\Finance\Reports\ProfitAndLoss;
use App\Domain\Finance\Reports\ReportPeriod;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Reporting\Queries\Sales\SalesAnalytics;
use App\Domain\Reporting\Support\DateRange;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Sales analytics (P8): Dhaka-date bucketing at the day / Saturday-week / month edges, placed
 * demand kept apart from recognised revenue, and — for a seeded month — every recognised
 * figure equal to the ledger to the poisha (roadmap Phase 8 testing).
 */

/** @param  list<array{0: Product, 1: int, 2: string, 3: string, 4?: string|null}>  $lines  [product, qty, unit price, line total, source] */
function salesOrder(Customer $customer, string $placedUtc, OrderStatus $status, array $lines, array $attributes = []): Order
{
    $subtotal = '0.00';
    foreach ($lines as $line) {
        $subtotal = bcadd($subtotal, bcmul($line[2], (string) $line[1], 2), 2);
    }
    $discount = $attributes['discount_total'] ?? '0.00';
    $shipping = $attributes['shipping_charge'] ?? '70.00';
    $grand = bcadd(bcsub($subtotal, $discount, 2), $shipping, 2);

    $order = Order::factory()->status($status)->create([
        'customer_id' => $customer->id,
        'placed_at' => CarbonImmutable::parse($placedUtc, 'UTC'),
        'subtotal' => $subtotal,
        'shipping_charge' => $shipping,
        'grand_total' => $grand,
        'due_total' => $grand,
        ...$attributes,
    ]);
    foreach ($lines as $line) {
        $variant = $line[0]->variants()->firstOrFail();
        OrderItem::factory()->create([
            'order_id' => $order->id, 'variant_id' => $variant->id, 'product_id' => $line[0]->id,
            'product_name' => $line[0]->name, 'sku' => $variant->sku,
            'unit_price' => $line[2], 'quantity' => $line[1], 'line_total' => $line[3],
            'cost_price_snapshot' => '600.0000', 'quantity_shipped' => $line[1],
            'recommendation_source' => $line[4] ?? null,
        ]);
    }
    if ($order->delivered_at !== null) {
        app(RecognizeOrderRevenue::class)->handle($order->id);
    }

    return $order;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-05 06:00:00', 'UTC'));
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->puzzles = Category::factory()->create(['name' => 'Puzzles', 'name_bn' => 'পাজল']);
    $this->dolls = Category::factory()->create(['name' => 'Dolls', 'name_bn' => 'পুতুল']);
    $this->kidz = Brand::factory()->create(['name' => 'Kidz', 'name_bn' => 'কিডজ']);
    $this->puzzle = Product::factory()->create(['name' => 'Wooden Puzzle', 'primary_category_id' => $this->puzzles->id, 'brand_id' => $this->kidz->id]);
    $this->doll = Product::factory()->create(['name' => 'Rag Doll', 'primary_category_id' => $this->dolls->id]);

    $this->rina = Customer::factory()->create();
    $this->karim = Customer::factory()->create();
    $this->nadia = Customer::factory()->create();

    // October: Rina's first order (makes her November order a repeat purchase).
    salesOrder($this->rina, '2026-10-15 04:00:00', OrderStatus::Delivered, [[$this->doll, 1, '800.00', '800.00']], ['delivered_at' => CarbonImmutable::parse('2026-10-17 06:00:00', 'UTC')]);

    // A: placed Mon 2 Nov, delivered Tue 3 Nov: 2 puzzles at 1,200, delivery 70 → 2,470. Via a PDP cross-sell.
    $this->a = salesOrder($this->rina, '2026-11-02 04:00:00', OrderStatus::Delivered, [[$this->puzzle, 2, '1200.00', '2400.00', 'pdp_cross_sell']], ['delivered_at' => CarbonImmutable::parse('2026-11-03 06:00:00', 'UTC'), 'payment_method' => 'cod', 'source' => 'web']);

    // B: placed 23:59 Dhaka Fri 6 Nov (17:59 UTC) — still week Sat 31 Oct; coupon SAVE10 100 off a 1,000 doll → 970.
    $this->b = salesOrder($this->karim, '2026-11-06 17:59:00', OrderStatus::Completed, [[$this->doll, 1, '1000.00', '900.00']], [
        'delivered_at' => CarbonImmutable::parse('2026-11-08 05:00:00', 'UTC'),
        'discount_total' => '100.00', 'coupon_code' => 'save10', 'coupon_discount' => '100.00', 'payment_method' => 'bkash', 'source' => 'facebook',
    ]);

    // C: placed 18:30 UTC Sat 7 Nov = 00:30 Dhaka Sun 8 Nov — week Sat 7 Nov. Cancelled: demand reported apart, no revenue.
    $this->c = salesOrder($this->nadia, '2026-11-07 18:30:00', OrderStatus::Cancelled, [[$this->puzzle, 1, '500.00', '500.00']], ['cancelled_at' => now()]);

    // D: placed 30 Nov, delivered 19:00 UTC 30 Nov = 01:00 Dhaka 1 Dec: its revenue is December's.
    $this->d = salesOrder($this->nadia, '2026-11-30 10:00:00', OrderStatus::Delivered, [[$this->doll, 2, '450.00', '900.00']], ['delivered_at' => CarbonImmutable::parse('2026-11-30 19:00:00', 'UTC'), 'shipping_charge' => '130.00']);

    // E: Rina again, placed 20 Nov, still shipped (in the courier's van): demand, not revenue.
    $this->e = salesOrder($this->rina, '2026-11-20 08:00:00', OrderStatus::Shipped, [[$this->puzzle, 1, '1250.00', '1250.00']]);

    // F: placed 1 Nov 00:10 Dhaka (31 Oct 18:10 UTC) — the first minute of the month in Dhaka.
    $this->f = salesOrder($this->karim, '2026-10-31 18:10:00', OrderStatus::Delivered, [[$this->puzzle, 1, '1200.00', '1200.00']], ['delivered_at' => CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC')]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function novemberReport(ReportGranularity $g = ReportGranularity::Week, bool $profit = true): array
{
    return app(SalesAnalytics::class)->report(DateRange::custom('2026-11-01', '2026-11-30'), $g, $profit);
}

it('matches the ledger to the poisha for a seeded month', function (): void {
    $report = novemberReport();
    $r = $report['summary']['recognised'];

    // Hand: delivered in Nov (Dhaka) = A 2,470 + B 970 + F 1,270 = 4,710. D lands on 1 Dec.
    expect($r['net_revenue']->toDecimal())->toBe('4710.00');

    $ledger = DB::table('financial_transactions')
        ->whereBetween('occurred_on', ['2026-11-01', '2026-11-30'])
        ->where('is_recognised_revenue', true)
        ->selectRaw("SUM(CASE WHEN type IN ('sale_revenue','shipping_revenue') THEN amount WHEN type = 'discount_given' THEN -amount ELSE 0 END) as net_revenue")
        ->selectRaw("SUM(CASE WHEN type = 'cogs' THEN amount ELSE 0 END) as cogs")
        ->first();
    $statement = app(ProfitAndLoss::class)->statement(ReportPeriod::of('2026-11-01', '2026-11-30'), 'none');

    expect($r['net_revenue']->toDecimal())->toBe(sprintf('%.2F', $ledger->net_revenue))
        ->and($r['net_revenue']->equals($statement['totals']['net_revenue']))->toBeTrue()
        ->and($r['gross_profit']->equals($statement['totals']['gross_profit']))->toBeTrue()
        // COGS: A 2×600 + B 1×600 + F 1×600 = 2,400 → gross profit 2,310.
        ->and($r['gross_profit']->toDecimal())->toBe('2310.00')
        ->and($r['cogs']->toDecimal())->toBe('-'.sprintf('%.2F', $ledger->cogs))
        ->and($r['discounts']->toDecimal())->toBe('-100.00')
        // The trend's recognised revenue adds back up to the total.
        ->and(array_reduce($report['trend'], fn (string $c, array $b): string => bcadd($c, $b['recognised_revenue']->toDecimal(), 2), '0.00'))->toBe('4710.00')
        // Product lines reconcile with the ledger's product revenue after discounts.
        ->and($report['reconciliation']['difference']->toDecimal())->toBe('0.00')
        ->and($report['reconciliation']['lines_net_revenue']->toDecimal())->toBe('4500.00');
});

it('keeps placed demand apart from recognised revenue and reports cancellations separately', function (): void {
    $p = novemberReport()['summary']['placed'];

    // Placed in Nov (Dhaka): F 1,270 · A 2,470 · B 970 · C 570 (cancelled) · E 1,320 · D 1,030.
    expect($p['orders'])->toBe(6)
        ->and($p['value']->toDecimal())->toBe('7630.00')
        ->and($p['cancelled_orders'])->toBe(1)
        ->and($p['cancelled_value']->toDecimal())->toBe('570.00')
        ->and($p['net_orders'])->toBe(5)
        ->and($p['net_value']->toDecimal())->toBe('7060.00')
        ->and($p['aov']->toDecimal())->toBe('1412.00')
        ->and($p['units'])->toBe(7)
        ->and($p['cancel_rate'])->toBe('16.67');
});

it('buckets by Dhaka date at the day, Saturday-week and month edges', function (): void {
    $weeks = novemberReport(ReportGranularity::Week)['trend'];
    expect(array_column($weeks, 'key'))->toBe(['2026-11-01', '2026-11-07', '2026-11-14', '2026-11-21', '2026-11-28'])
        // Week 1 (Sun 1 – Fri 6, clipped): F (1 Nov 00:10 Dhaka), A, and B at 23:59 on Friday.
        ->and($weeks[0]['placed_orders'])->toBe(3)
        ->and($weeks[0]['placed_value']->toDecimal())->toBe('4710.00')
        // Week 2 (Sat 7 –): C, placed 00:30 Dhaka Sunday — cancelled, so in outcomes only.
        ->and($weeks[1]['placed_orders'])->toBe(0)
        ->and($weeks[1]['cancelled_orders'])->toBe(1)
        ->and($weeks[1]['outcomes']['cancelled'])->toBe(1)
        // B delivered 8 Nov (Dhaka) → week 2's recognised revenue.
        ->and($weeks[1]['recognised_revenue']->toDecimal())->toBe('970.00')
        ->and($weeks[1]['delivered_orders'])->toBe(1);

    $days = novemberReport(ReportGranularity::Day)['trend'];
    $byDay = array_column($days, null, 'key');
    expect($byDay['2026-11-01']['placed_orders'])->toBe(1)
        ->and($byDay['2026-11-06']['placed_orders'])->toBe(1)
        ->and($byDay['2026-11-07']['cancelled_orders'])->toBe(0)
        ->and($byDay['2026-11-08']['cancelled_orders'])->toBe(1)
        ->and($byDay['2026-11-30']['recognised_revenue']->toDecimal())->toBe('0.00');

    // Month: October holds Rina's first order only, not F (1 Nov in Dhaka, 31 Oct in UTC).
    $months = app(SalesAnalytics::class)->report(DateRange::custom('2026-10-01', '2026-11-30'), ReportGranularity::Month, true)['trend'];
    expect($months[0]['key'])->toBe('2026-10-01')
        ->and($months[0]['placed_orders'])->toBe(1)
        ->and($months[1]['placed_orders'])->toBe(5);
});

it('reports category and brand performance equal to the profitability report', function (): void {
    $report = novemberReport();
    $byCategory = app(Profitability::class)->byCategory(ReportPeriod::of('2026-11-01', '2026-11-30'));

    $total = array_reduce($report['categories'], fn (string $c, array $row): string => bcadd($c, $row['net_revenue']->toDecimal(), 2), '0.00');
    expect($total)->toBe($byCategory['totals']['net_revenue']->toDecimal())
        ->and($report['categories'][0]['name'])->toBe('Puzzles')
        ->and($report['categories'][0]['net_revenue']->toDecimal())->toBe('3600.00')
        ->and($report['categories'][0]['units'])->toBe(3)
        ->and($report['categories'][0]['gross_profit']->toDecimal())->toBe('1800.00')
        ->and($report['categories'][0]['return_rate'])->toBe('0.00')
        ->and(array_column($report['brands'], 'name'))->toBe(['Kidz', null]);
});

it('splits payment methods and channels, measures discounts and repeat purchase', function (): void {
    $report = novemberReport();

    expect(array_column($report['payment_methods'], 'method'))->toBe(['cod', 'bkash'])
        ->and($report['payment_methods'][0]['orders'])->toBe(4)
        ->and($report['payment_methods'][0]['orders_percent'])->toBe('80.00')
        ->and(array_column($report['channels'], 'source'))->toBe(['web', 'facebook'])
        ->and($report['discounts']['orders_with_discount'])->toBe(1)
        ->and($report['discounts']['coupon_discount']->toDecimal())->toBe('100.00')
        ->and($report['discounts']['coupons'][0]['code'])->toBe('SAVE10')
        ->and($report['discounts']['aov_with_discount']->toDecimal())->toBe('970.00')
        // Rina ordered in October; Karim twice in November; Nadia once (C was cancelled, D counts).
        ->and($report['summary']['customers'])->toBe(['customers' => 3, 'returning' => 2, 'new' => 1, 'repeat_rate' => '66.67'])
        ->and($report['conversion'])->toBe(['available' => false, 'reason' => 'no_session_denominator']);
});

it('attributes recommendation placements from order_items.recommendation_source', function (): void {
    $rows = array_column(novemberReport()['recommendations'], null, 'source');

    expect($rows['pdp_cross_sell']['placed_units'])->toBe(2)
        ->and($rows['pdp_cross_sell']['placed_value']->toDecimal())->toBe('2400.00')
        ->and($rows['pdp_cross_sell']['net_revenue']->toDecimal())->toBe('2400.00')
        ->and($rows['none']['placed_orders'])->toBe(4)
        ->and(array_key_last($rows))->toBe('none');
});

it('serves the report over the API and hides cost and profit from a viewer', function (): void {
    $manager = User::factory()->withRole(Role::Manager)->create()->refresh();
    $viewer = User::factory()->withRole(Role::Viewer)->create()->refresh();
    $query = '/api/v1/admin/analytics/sales?range=custom&filter[from]=2026-11-01&filter[to]=2026-11-30&group=week';

    $this->actingAs($manager)->getJson($query)->assertOk()
        ->assertJsonPath('profit_visible', true)
        ->assertJsonPath('summary.recognised.net_revenue', '4710.00')
        ->assertJsonPath('summary.recognised.gross_profit', '2310.00')
        ->assertJsonPath('trend.0.key', '2026-11-01')
        ->assertJsonPath('range', ['preset' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-30', 'days' => 30]);

    $this->actingAs($viewer)->getJson($query)->assertOk()
        ->assertJsonPath('profit_visible', false)
        ->assertJsonPath('summary.recognised.gross_profit', null)
        ->assertJsonPath('summary.recognised.cogs', null)
        ->assertJsonPath('categories.0.gross_profit', null)
        ->assertJsonPath('summary.recognised.net_revenue', '4710.00');
});

it('rejects an unlisted parameter, an unknown preset, a half range and a future date with 400', function (string $query): void {
    $manager = User::factory()->withRole(Role::Manager)->create()->refresh();

    $this->actingAs($manager)->getJson('/api/v1/admin/analytics/sales?'.$query)->assertStatus(400)->assertJsonPath('type', fn (string $t): bool => str_ends_with($t, 'bad-request'));
})->with([
    'unlisted' => 'sort=name',
    'unlisted filter' => 'filter[status]=x',
    'preset' => 'range=90d',
    'half' => 'range=custom&filter[from]=2026-11-01',
    'dates with preset' => 'range=7d&filter[from]=2026-11-01&filter[to]=2026-11-02',
    'future' => 'filter[from]=2026-12-01&filter[to]=2027-01-01',
    'group' => 'group=year',
    'too many buckets' => 'filter[from]=2015-01-01&filter[to]=2026-12-01&group=day',
]);
