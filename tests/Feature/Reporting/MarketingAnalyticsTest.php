<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketing\Models\AdSpend;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\CouponUsage;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Models\PromotionUsage;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Reporting\Queries\Marketing\MarketingAnalytics;
use App\Domain\Reporting\Support\DateRange;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\RoleMatrix;

/*
 * Marketing ROI (P10): spend typed in per campaign and month against the revenue its tagged
 * orders brought on delivery (rule 10), Dhaka dates (rule 11), and what each coupon and
 * promotion cost against what it brought — every figure hand-checked below.
 */

const MARKETING_URL = '/api/v1/admin/analytics/marketing';

/** One order with one line; `delivered` is a UTC timestamp or null. */
function roiOrder(Customer $customer, Product $product, string $placedUtc, OrderStatus $status, string $lineTotal, ?string $campaign, ?string $delivered = null, array $attributes = []): Order
{
    $order = Order::factory()->status($status)->total(bcadd($lineTotal, '0', 2))->create([
        'customer_id' => $customer->id,
        'placed_at' => CarbonImmutable::parse($placedUtc, 'UTC'),
        'delivered_at' => $delivered === null ? null : CarbonImmutable::parse($delivered, 'UTC'),
        'utm_campaign' => $campaign,
        'utm_source' => $campaign === null ? null : 'facebook',
        ...$attributes,
    ]);
    $variant = $product->variants()->firstOrFail();
    OrderItem::factory()->create([
        'order_id' => $order->id, 'variant_id' => $variant->id, 'product_id' => $product->id,
        'product_name' => $product->name, 'sku' => $variant->sku,
        'unit_price' => $lineTotal, 'quantity' => 1, 'line_total' => $lineTotal,
        'cost_price_snapshot' => '500.0000', 'quantity_shipped' => 1,
    ]);

    return $order;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 06:00:00', 'UTC'));
    $this->seed(RolesAndPermissionsSeeder::class);

    $toy = Product::factory()->create(['name' => 'Ride-on car', 'name_bn' => 'রাইড-অন গাড়ি']);
    $rina = Customer::factory()->create();
    $karim = Customer::factory()->create();
    $nadia = Customer::factory()->create();

    // October spend, typed in by hand: ৳9,500 over three campaigns.
    AdSpend::factory()->create(['campaign' => 'winter-toy-fest', 'source' => 'facebook', 'month' => '2026-10-01', 'amount' => '4000.00']);
    AdSpend::factory()->create(['campaign' => 'eid-retarget', 'source' => 'facebook', 'month' => '2026-10-01', 'amount' => '3500.00']);
    AdSpend::factory()->create(['campaign' => 'search-ride-on', 'source' => 'google', 'month' => '2026-10-01', 'amount' => '2000.00']);
    // November spend: never in an October range.
    AdSpend::factory()->create(['campaign' => 'winter-toy-fest', 'source' => 'facebook', 'month' => '2026-11-01', 'amount' => '3000.00']);

    // D: Rina's first order, no tag, delivered 4 Oct.
    $this->d = roiOrder($rina, $toy, '2026-10-03 06:00:00', OrderStatus::Delivered, '1500.00', null, '2026-10-04 06:00:00');
    // A: winter-toy-fest, delivered 7 Oct — ৳9,000. A promotion took ৳300 off.
    $this->a = roiOrder($rina, $toy, '2026-10-05 06:00:00', OrderStatus::Delivered, '9000.00', 'winter-toy-fest', '2026-10-07 06:00:00');
    // B: the same campaign, tagged in another case — Karim's first order, coupon WELCOME100.
    $this->b = roiOrder($karim, $toy, '2026-10-10 06:00:00', OrderStatus::Completed, '9400.00', 'Winter-Toy-Fest', '2026-10-12 06:00:00');
    // E: a free newsletter tag (no spend) — delivered ৳700.
    roiOrder($karim, $toy, '2026-10-15 06:00:00', OrderStatus::Delivered, '700.00', 'newsletter', '2026-10-16 06:00:00');
    // F: Nadia's cancelled order — counts nowhere.
    roiOrder($nadia, $toy, '2026-10-18 06:00:00', OrderStatus::Cancelled, '800.00', 'eid-retarget', null, ['cancelled_at' => now()]);
    // C: eid-retarget, still in the van — demand, no revenue. Nadia's first real order, WELCOME100.
    $this->c = roiOrder($nadia, $toy, '2026-10-20 06:00:00', OrderStatus::Shipped, '2200.00', 'eid-retarget');

    $welcome = Coupon::factory()->create(['code' => 'WELCOME100']);
    foreach ([$this->b, $this->c] as $order) {
        CouponUsage::query()->create(['coupon_id' => $welcome->id, 'order_id' => $order->id, 'customer_id' => $order->customer_id, 'phone' => '+8801711000000', 'discount_amount' => '100.00', 'used_at' => $order->placed_at]);
    }
    $winter = Promotion::factory()->create(['name' => 'Winter Toy Fest', 'name_bn' => 'শীতের খেলনা উৎসব']);
    PromotionUsage::query()->create(['promotion_id' => $winter->id, 'order_id' => $this->a->id, 'customer_id' => $this->a->customer_id, 'phone' => '+8801711000001', 'discount_amount' => '300.00', 'used_at' => $this->a->placed_at]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function october(): array
{
    return app(MarketingAnalytics::class)->report(DateRange::custom('2026-10-01', '2026-10-31'));
}

it('works out spend, delivered revenue, return and cost per order per campaign', function (): void {
    $r = october();
    $s = $r['summary'];

    // Spend ৳9,500; back ৳9,000 + ৳9,400 (both winter orders); 3 orders from paid campaigns (A, B, C).
    expect($s['spend']->toDecimal())->toBe('9500.00')
        ->and($s['revenue']->toDecimal())->toBe('18400.00')
        ->and($s['orders'])->toBe(3)
        ->and($s['delivered_orders'])->toBe(2)
        ->and($s['campaigns'])->toBe(3)
        ->and($s['return_ratio'])->toBe('1.94')              // 18,400 ÷ 9,500 = 1.9368
        ->and($s['cost_per_order']->toDecimal())->toBe('3166.67') // 9,500 ÷ 3
        ->and($s['unpaid_revenue']->toDecimal())->toBe('700.00')
        ->and($s['unpaid_orders'])->toBe(1);

    $rows = collect($r['campaigns'])->keyBy('campaign');
    expect(array_column($r['campaigns'], 'campaign'))->toBe(['winter-toy-fest', 'eid-retarget', 'search-ride-on', 'newsletter']);

    $winter = $rows['winter-toy-fest'];
    expect($winter['spend']->toDecimal())->toBe('4000.00')
        ->and($winter['orders'])->toBe(2)
        ->and($winter['delivered_orders'])->toBe(2)
        ->and($winter['delivered_revenue']->toDecimal())->toBe('18400.00')
        ->and($winter['return_ratio'])->toBe('4.60')
        ->and($winter['cost_per_order']->toDecimal())->toBe('2000.00')
        ->and($winter['source'])->toBe('facebook');

    expect($rows['eid-retarget']['orders'])->toBe(1)
        ->and($rows['eid-retarget']['return_ratio'])->toBe('0.00')
        ->and($rows['search-ride-on']['orders'])->toBe(0)
        ->and($rows['search-ride-on']['cost_per_order'])->toBeNull()
        ->and($rows['newsletter']['has_spend'])->toBeFalse()
        ->and($rows['newsletter']['return_ratio'])->toBeNull()
        ->and($rows['newsletter']['delivered_revenue']->toDecimal())->toBe('700.00');

    expect($r['highlights']['best_campaign']['campaign'])->toBe('winter-toy-fest')
        ->and($r['rules'])->toBe(['spend' => 'prorated_by_day', 'revenue' => 'delivered_net_product_revenue'])
        ->and($r['empty'])->toBeFalse();
});

it('puts untagged orders in one "No campaign tag" row with their sources and no spend', function (): void {
    $u = october()['untagged'];

    expect($u['orders'])->toBe(1)
        ->and($u['delivered_orders'])->toBe(1)
        ->and($u['delivered_revenue']->toDecimal())->toBe('1500.00')
        ->and($u['sources'])->toBe(['web']);
});

it('prorates a month of spend by the days of it inside the range', function (): void {
    $r = app(MarketingAnalytics::class)->report(DateRange::custom('2026-10-01', '2026-10-10'));
    $winter = collect($r['campaigns'])->firstWhere('campaign', 'winter-toy-fest');

    // 4,000 × 10 / 31 = 1,290.3226 → 1,290.32. Spanning two months adds each month's share.
    expect($winter['spend']->toDecimal())->toBe('1290.32');
    $span = app(MarketingAnalytics::class)->report(DateRange::custom('2026-10-31', '2026-11-01'));
    $winterSpan = collect($span['campaigns'])->firstWhere('campaign', 'winter-toy-fest');
    // 4,000 / 31 = 129.03 + 3,000 / 30 = 100.00
    expect($winterSpan['spend']->toDecimal())->toBe('229.03');
});

it('costs each coupon and promotion and counts the new customers they brought', function (): void {
    $r = october();
    $deals = collect($r['deals'])->keyBy('name');

    $welcome = $deals['WELCOME100'];
    expect($welcome['kind'])->toBe('coupon')
        ->and($welcome['orders'])->toBe(2)
        ->and($welcome['new_customers'])->toBe(2)            // B is Karim's first, C Nadia's first non-cancelled
        ->and($welcome['cost']->toDecimal())->toBe('200.00')
        ->and($welcome['order_value']->toDecimal())->toBe('11600.00')
        ->and($welcome['delivered_orders'])->toBe(1)
        ->and($welcome['delivered_value']->toDecimal())->toBe('9400.00');

    $winter = $deals['Winter Toy Fest'];
    expect($winter['kind'])->toBe('promotion')
        ->and($winter['name_bn'])->toBe('শীতের খেলনা উৎসব')
        ->and($winter['new_customers'])->toBe(0)             // Rina ordered before (D)
        ->and($winter['cost']->toDecimal())->toBe('300.00')
        ->and($winter['delivered_value']->toDecimal())->toBe('9000.00');

    expect($r['deal_totals']['coupon']['cost']->toDecimal())->toBe('200.00')
        ->and($r['deal_totals']['coupon']['orders'])->toBe(2)
        ->and($r['deal_totals']['promotion']['cost']->toDecimal())->toBe('300.00')
        ->and($r['highlights']['top_new_customer_coupon'])->toBe(['name' => 'WELCOME100', 'new_customers' => 2]);
});

it('serves a custom Dhaka range over HTTP as decimal strings', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Viewer)->create()->refresh());

    $this->getJson(MARKETING_URL.'?range=custom&filter[from]=2026-10-01&filter[to]=2026-10-31')
        ->assertOk()
        ->assertJsonPath('range', ['preset' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31', 'days' => 31])
        ->assertJsonPath('summary.spend', '9500.00')
        ->assertJsonPath('summary.revenue', '18400.00')
        ->assertJsonPath('summary.return_ratio', '1.94')
        ->assertJsonPath('summary.cost_per_order', '3166.67')
        ->assertJsonPath('campaigns.0.campaign', 'winter-toy-fest')
        ->assertJsonPath('campaigns.0.return_ratio', '4.60')
        ->assertJsonPath('untagged.delivered_revenue', '1500.00')
        ->assertJsonPath('deals.0.name', 'WELCOME100')
        ->assertJsonPath('deals.0.cost', '200.00');

    // Only filter[from]/filter[to] and range are allowed (rule 16); dates are not in the future.
    $this->getJson(MARKETING_URL.'?filter[campaign]=x')->assertStatus(400);
    $this->getJson(MARKETING_URL.'?group=day')->assertStatus(400);
    $this->getJson(MARKETING_URL.'?range=custom&filter[from]=2026-10-01&filter[to]=2027-01-01')->assertStatus(400);
    $this->getJson(MARKETING_URL.'?range=forever')->assertStatus(400);
});

it('says empty when a range has no orders, spend or deals', function (): void {
    expect(app(MarketingAnalytics::class)->report(DateRange::custom('2026-01-01', '2026-01-31'))['empty'])->toBeTrue();
});

it('guards GET /analytics/marketing with reports.view', function (): void {
    RoleMatrix::assert($this, Permission::ReportsView, fn () => $this->getJson(MARKETING_URL));
});
