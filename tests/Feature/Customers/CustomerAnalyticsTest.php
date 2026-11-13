<?php

declare(strict_types=1);

use App\Domain\Customer\Actions\RebuildCustomerAggregates;
use App\Domain\Customer\Enums\CustomerSegment;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Support\SegmentThresholds;
use App\Domain\Finance\Reports\LedgerTotals;
use App\Domain\Finance\Reports\ReportPeriod;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Reporting\Queries\Customers\CustomerAnalytics;
use App\Domain\Reporting\Support\DateRange;
use App\Models\Enums\Permission as P;
use App\Models\Enums\Role;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finance\FinanceKit;
use Tests\Support\RoleMatrix;

/*
 * Customer analytics (P8): lifetime value from the ledger (never placed value), Dhaka-month
 * cohorts, repeat-purchase rate, segments, and the 403 matrix.
 */

/** A delivered order for this customer through the real state machine, so the ledger is written. */
function caDelivered(Customer $customer): Order
{
    $order = FinanceKit::shippedOrder();
    $order->forceFill(['customer_id' => $customer->id])->saveQuietly();

    return FinanceKit::deliver($order->refresh());
}

it('guards the customer analytics endpoint: reports.view and customers.view', function (): void {
    foreach (Role::cases() as $role) {
        $allowed = RoleMatrix::allows($role, P::ReportsView) && RoleMatrix::allows($role, P::CustomersView);
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/reports/customers'));
    }
});

it('answers 400 to an unlisted parameter, an unknown preset or a malformed date', function (): void {
    FinanceKit::actingAs(Role::Manager);

    foreach (['filter[x]=1', 'range=forever', 'range=7d&from=2026-11-01', 'range=custom&from=2026-11-01', 'range=custom&from=2026-02-30&to=2026-03-01', 'sort=ltv'] as $query) {
        $this->getJson('/api/v1/admin/reports/customers?'.$query)->assertStatus(400);
    }
    $this->getJson('/api/v1/admin/reports/customers?range=this_month')->assertOk()->assertJsonPath('range.preset', 'this_month');
});

it('reports lifetime value equal to the ledger, to the poisha, for a seeded month', function (): void {
    $user = FinanceKit::actingAs(Role::SuperAdmin);
    $alice = Customer::factory()->create(['name' => 'Alice']);
    $bilkis = Customer::factory()->create(['name' => 'Bilkis']);
    $chaity = Customer::factory()->create(['name' => 'Chaity']);
    caDelivered($alice);
    caDelivered($alice);
    caDelivered($alice);
    caDelivered($bilkis);
    // Placed or shipped but not delivered: never part of lifetime value (rule 10).
    $pending = FinanceKit::shippedOrder();
    $pending->forceFill(['customer_id' => $chaity->id])->saveQuietly();
    Order::factory()->for($chaity)->total('9999.99')->create();
    app(RebuildCustomerAggregates::class)->handle();

    $period = ReportPeriod::of(substr(DhakaDate::today(), 0, 8).'01', DhakaDate::today());
    $ledger = app(LedgerTotals::class)->recognisedByType($period);
    $ledgerLtv = Money::zero();
    foreach (['sale_revenue', 'discount_given', 'refund'] as $type) {
        $ledgerLtv = $ledgerLtv->plus($ledger[$type] ?? Money::zero());
    }

    $values = app(CustomerAnalytics::class)->lifetimeValues();
    $sum = array_reduce($values, static fn (Money $c, Money $m): Money => $c->plus($m), Money::zero());
    expect($sum->toDecimal())->toBe($ledgerLtv->toDecimal())
        ->and($ledgerLtv->toDecimal())->toBe('4400.00') // 4 deliveries × (1200 − 100 discount); shipping excluded
        ->and($values[$alice->id]->toDecimal())->toBe('3300.00')
        ->and($values)->not->toHaveKey($chaity->id);

    $list = collect($this->getJson('/api/v1/admin/customers?sort=-lifetime_value')->assertOk()->json('data'))->keyBy('name');
    expect($list['Alice']['lifetime_value'])->toBe('3300.00')
        ->and($list['Bilkis']['lifetime_value'])->toBe('1100.00')
        ->and($list['Chaity']['lifetime_value'])->toBe('0.00');

    $profile = $this->getJson("/api/v1/admin/customers/{$alice->id}")->assertOk()->json('stats');
    expect($profile['lifetime_value'])->toBe('3300.00')
        ->and($profile['average_order_value'])->toBe('1100.00')
        ->and($profile['delivered_orders_count'])->toBe(3);

    $report = $this->getJson('/api/v1/admin/reports/customers')->assertOk();
    expect($report->json('lifetime_value.total'))->toBe('4400.00')
        ->and($report->json('lifetime_value.customers_with_value'))->toBe(2)
        ->and($report->json('lifetime_value.median'))->toBe('2200.00')
        ->and($report->json('top_customers.0.name'))->toBe('Alice')
        ->and($report->json('top_customers.0.phone_masked'))->toMatch('/^01\d\*{4}\d{4}$/')
        ->and($report->json('repeat_purchase'))->toBe(['customers_with_delivered' => 2, 'repeat_customers' => 1, 'rate' => '50.00']);
    FinanceKit::assertFinanceInvariant();
    expect($user)->not->toBeNull();
});

it('builds acquisition cohorts on Dhaka month edges, not UTC', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-15 06:00:00', 'UTC'));
    $make = static function (string $utc, ?Customer $customer = null, OrderStatus $status = OrderStatus::Pending): Customer {
        $customer ??= Customer::factory()->create();
        Order::factory()->for($customer)->status($status)->create(['created_at' => $utc, 'placed_at' => $utc]);

        return $customer;
    };

    // 30 Sep 18:30 UTC is 1 Oct 00:30 in Dhaka → the October cohort.
    $oct1 = $make('2026-09-30 18:30:00');
    // 31 Oct 17:59 UTC is still 31 Oct 23:59 in Dhaka → October; it orders again on 1 Nov (Dhaka).
    $oct2 = $make('2026-10-31 17:59:00');
    $make('2026-10-31 18:00:00', $oct2);
    // 30 Sep 17:59 UTC is 30 Sep 23:59 Dhaka → September, outside the range.
    $sep = $make('2026-09-30 17:59:00');
    $make('2026-11-20 10:00:00', $sep);
    // A cancelled first order does not acquire anyone; the November order does.
    $nov = $make('2026-10-15 10:00:00', null, OrderStatus::Cancelled);
    $make('2026-11-05 10:00:00', $nov);

    $cohorts = app(CustomerAnalytics::class)->cohorts(DateRange::custom('2026-10-01', '2026-11-30'), []);

    expect(array_column($cohorts, 'month'))->toBe(['2026-10', '2026-11'])
        ->and($cohorts[0]['customers'])->toBe(2)
        ->and($cohorts[0]['retention'])->toBe([
            ['offset' => 0, 'month' => '2026-10', 'customers' => 2, 'rate' => '100.00'],
            ['offset' => 1, 'month' => '2026-11', 'customers' => 1, 'rate' => '50.00'],
            ['offset' => 2, 'month' => '2026-12', 'customers' => 0, 'rate' => '0.00'],
        ])
        ->and($cohorts[1]['customers'])->toBe(1)
        ->and($cohorts[1]['retention'][0]['rate'])->toBe('100.00');
    expect($oct1->id)->toBeInt();

    CarbonImmutable::setTestNow();
});

it('counts first-time and returning customers inside the range', function (): void {
    $old = Customer::factory()->create();
    Order::factory()->for($old)->create(['created_at' => now()->subDays(60), 'placed_at' => now()->subDays(60)]);
    Order::factory()->for($old)->create(['created_at' => now()->subDays(2)]);
    $new = Customer::factory()->create();
    Order::factory()->for($new)->create(['created_at' => now()->subDays(1)]);

    expect(app(CustomerAnalytics::class)->period(DateRange::fromPreset('7d')))
        ->toBe(['customers' => 2, 'new_customers' => 1, 'returning_customers' => 1, 'returning_share' => '50.00']);
});

it('assigns segments by the placeholder thresholds, in precedence order, the same in PHP and SQL', function (): void {
    $t = SegmentThresholds::fromConfig();
    $now = CarbonImmutable::now('UTC');
    expect($t->classify(0, null, Money::zero(), $now))->toBe(CustomerSegment::NoOrders)
        ->and($t->classify(5, $now->subDays(200), Money::of('90000.00'), $now))->toBe(CustomerSegment::Lapsed)
        ->and($t->classify(5, $now->subDays(100), Money::of('90000.00'), $now))->toBe(CustomerSegment::AtRisk)
        ->and($t->classify(1, $now->subDays(1), Money::of('15000.00'), $now))->toBe(CustomerSegment::Vip)
        ->and($t->classify(1, $now->subDays(1), Money::of('14999.99'), $now))->toBe(CustomerSegment::New)
        ->and($t->classify(2, $now->subDays(89), Money::zero(), $now))->toBe(CustomerSegment::Active);

    config(['customers.segments.at_risk_after_days' => 30]);
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->count(2)->create(['created_at' => now()->subDays(40), 'placed_at' => now()->subDays(40)]);
    app(RebuildCustomerAggregates::class)->handle();

    $segments = collect(app(CustomerAnalytics::class)->segments(CarbonImmutable::now('UTC')))->keyBy('segment');
    expect($segments['at_risk']['customers'])->toBe(1)
        ->and($segments->keys()->all())->toBe(['no_orders', 'lapsed', 'at_risk', 'vip', 'new', 'active'])
        ->and(DB::table('customers')->count())->toBe(1);
});
