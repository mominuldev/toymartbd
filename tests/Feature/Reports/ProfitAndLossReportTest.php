<?php

declare(strict_types=1);

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType as T;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Domain\Finance\Reports\ProfitAndLoss;
use App\Domain\Finance\Reports\ReportPeriod;
use App\Domain\Finance\Services\DailySummaryRollup;
use App\Models\Enums\Role;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Xlsx;

/*
 * P&L statement and series (P5 reports): correctness on a hand-computed scenario with a
 * partial refund and a courier return, Dhaka-date bucketing, week/month/year boundaries,
 * the summary-table path for long ranges, and the cash view kept apart.
 */

/** Records one ledger entry through the real writer (agent 3's Ledger contract). */
function pnlEntry(T $type, Direction $direction, string $amount, string $on, string $sourceType, int $sourceId, bool $recognised = true, bool $cash = false, ?int $categoryId = null): void
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
        categoryId: $categoryId,
    )));
}

/**
 * The week of 02–08 Nov 2026 (C8-style), hand-computed:
 *   order 1 delivered: 4,900 + 160 delivery − 100 coupon, COGS 3,250, courier 160, COD fee 51, packaging 50
 *   order 2 delivered: 4,350 + 200 delivery, COGS 2,550, courier 200, COD fee 46, packaging 25, PARTIAL REFUND 300
 *   order 3 COURIER RETURN (never delivered): courier 180, return charge 70, packaging 50 — no revenue
 *   order 4 delivered then reversed (returned): sale 1,200 / COGS 640, both reversed → nets to zero
 *   period costs: write-off 1,280, advertising 1,400 (category Ads), SMS 500 (category SMS)
 *   cash only: COD remittance 9,153 in, supplier payment 5,000 out; the refund also moved cash
 */
function pnlScenario(): array
{
    $ads = ExpenseCategory::query()->create(['name' => 'Facebook ads', 'name_bn' => 'ফেসবুক বিজ্ঞাপন', 'type' => 'marketing']);
    $sms = ExpenseCategory::query()->create(['name' => 'SMS credit', 'name_bn' => 'এসএমএস ক্রেডিট', 'type' => 'operating']);

    $in = Direction::In;
    $out = Direction::Out;

    pnlEntry(T::SaleRevenue, $in, '4900.00', '2026-11-03', 'order', 1);
    pnlEntry(T::ShippingRevenue, $in, '160.00', '2026-11-03', 'order', 1);
    pnlEntry(T::DiscountGiven, $out, '100.00', '2026-11-03', 'order', 1);
    pnlEntry(T::Cogs, $out, '3250.00', '2026-11-03', 'order', 1);
    pnlEntry(T::CourierCharge, $out, '160.00', '2026-11-03', 'shipment', 1);
    pnlEntry(T::CourierCodFee, $out, '51.00', '2026-11-03', 'shipment', 1);
    pnlEntry(T::Packaging, $out, '50.00', '2026-11-03', 'shipment', 1);

    pnlEntry(T::SaleRevenue, $in, '4350.00', '2026-11-04', 'order', 2);
    pnlEntry(T::ShippingRevenue, $in, '200.00', '2026-11-04', 'order', 2);
    pnlEntry(T::Cogs, $out, '2550.00', '2026-11-04', 'order', 2);
    pnlEntry(T::CourierCharge, $out, '200.00', '2026-11-04', 'shipment', 2);
    pnlEntry(T::CourierCodFee, $out, '46.00', '2026-11-04', 'shipment', 2);
    pnlEntry(T::Packaging, $out, '25.00', '2026-11-04', 'shipment', 2);
    pnlEntry(T::Refund, $out, '300.00', '2026-11-06', 'refund', 1, cash: true);

    pnlEntry(T::CourierCharge, $out, '180.00', '2026-11-05', 'shipment', 3);
    pnlEntry(T::CourierReturnCharge, $out, '70.00', '2026-11-05', 'shipment', 3);
    pnlEntry(T::Packaging, $out, '50.00', '2026-11-05', 'shipment', 3);

    pnlEntry(T::SaleRevenue, $in, '1200.00', '2026-11-05', 'order', 4);
    pnlEntry(T::Cogs, $out, '640.00', '2026-11-05', 'order', 4);
    pnlEntry(T::SaleRevenue, $out, '1200.00', '2026-11-07', 'order_reversal', 4);
    pnlEntry(T::Cogs, $in, '640.00', '2026-11-07', 'order_reversal', 4);

    pnlEntry(T::InventoryWriteOff, $out, '1280.00', '2026-11-05', 'stock_adjustment', 12);
    pnlEntry(T::Marketing, $out, '1400.00', '2026-11-08', 'expense', 1, cash: true, categoryId: $ads->id);
    pnlEntry(T::OtherExpense, $out, '500.00', '2026-11-03', 'expense', 2, cash: true, categoryId: $sms->id);

    pnlEntry(T::CodReceived, $in, '9153.00', '2026-11-07', 'cod_settlement', 45, recognised: false, cash: true);
    pnlEntry(T::SupplierPayment, $out, '5000.00', '2026-11-04', 'supplier_payment', 9, recognised: false, cash: true);

    // Outside the week: the day before and the day after.
    pnlEntry(T::SaleRevenue, $in, '999.00', '2026-11-01', 'order', 90);
    pnlEntry(T::SaleRevenue, $in, '777.00', '2026-11-09', 'order', 91);

    return ['ads' => $ads, 'sms' => $sms];
}

function pnlAmounts(array $statement): array
{
    $lines = [];
    foreach ($statement['blocks'] as $block) {
        foreach ($block['rows'] as $row) {
            $lines[$row['key']] = $row['amount'] instanceof Money ? $row['amount']->toDecimal() : $row['amount'];
        }
    }

    return $lines;
}

function pnlStaff(Role $role = Role::Accountant): User
{
    (new RolesAndPermissionsSeeder)->run();

    return User::factory()->withRole($role)->create()->refresh();
}

it('computes the five-block statement for a week with a partial refund and a courier return', function (): void {
    pnlScenario();

    $statement = app(ProfitAndLoss::class)->statement(ReportPeriod::of('2026-11-02', '2026-11-08'), 'none');

    expect(pnlAmounts($statement))->toBe([
        'product_revenue' => '9250.00',     // 4,900 + 4,350 + 1,200 − 1,200 reversed
        'delivery_revenue' => '360.00',
        'gross_revenue' => '9610.00',
        'discounts' => '-100.00',
        'net_revenue' => '9510.00',
        'cogs' => '-5800.00',               // 3,250 + 2,550 + 640 − 640 reversed
        'gross_profit' => '3710.00',
        'courier_charges' => '-540.00',     // 160 + 200 + 180 (the returned parcel)
        'cod_fees' => '-97.00',
        'return_charges' => '-70.00',
        'gateway_fees' => '0.00',
        'packaging' => '-125.00',
        'profit_after_delivery' => '2878.00',
        'refunds' => '-300.00',             // the partial refund
        'write_offs' => '-1280.00',
        'profit_after_losses' => '1298.00',
        'marketing' => '-1400.00',
        'salaries' => '0.00',
        'rent' => '0.00',
        'utilities' => '0.00',
        'other_expenses' => '-500.00',
        'other_income' => '0.00',
        'net_profit' => '-602.00',
    ])
        ->and($statement['gross_margin_percent'])->toBe('39.01')
        ->and($statement['net_margin_percent'])->toBe('-6.33')
        // Cash is separate: the COD remittance and supplier payment never touch the P&L.
        ->and($statement['cash']['in']->toDecimal())->toBe('9153.00')
        ->and($statement['cash']['out']->toDecimal())->toBe('7200.00') // 300 refund + 1,400 ads + 500 SMS + 5,000 supplier
        ->and($statement['cash']['net']->toDecimal())->toBe('1953.00')
        ->and($statement['unmapped'])->toBe([])
        // Entries whose source is an order (or a row carrying an order id) + everything else = net profit.
        // Orders 1, 2 and 4 (incl. its reversal): 1,710 + 2,000 + 0. The shipment/refund/expense
        // sources here have no rows linking them to an order, so they are period costs.
        ->and($statement['reconciliation']['orders']->toDecimal())->toBe('3710.00')
        ->and($statement['reconciliation']['other']->toDecimal())->toBe('-4312.00')
        ->and($statement['reconciliation']['reconciled'])->toBeTrue();

    $categories = collect($statement['expenses_by_category'])->mapWithKeys(fn (array $c): array => [$c['name'] => $c['amount']->toDecimal()])->all();
    expect($categories)->toBe(['Facebook ads' => '-1400.00', 'SMS credit' => '-500.00']);
});

it('compares with the previous period and reports the deltas', function (): void {
    pnlScenario();
    pnlEntry(T::SaleRevenue, Direction::In, '5000.00', '2026-10-30', 'order', 50);

    $statement = app(ProfitAndLoss::class)->statement(ReportPeriod::of('2026-11-02', '2026-11-08'), 'previous_period');

    // Previous period 26 Oct – 01 Nov: 5,000 + 999 of sale revenue.
    expect($statement['comparison']['from'])->toBe('2026-10-26')
        ->and($statement['comparison']['to'])->toBe('2026-11-01')
        ->and($statement['comparison']['totals']['net_revenue']->toDecimal())->toBe('5999.00')
        ->and($statement['comparison']['deltas']['net_revenue'])->toBe('58.53'); // (9,510 − 5,999) / 5,999
});

it('buckets an entry recorded at 18:30 UTC on the next Dhaka day', function (): void {
    // 18:30 UTC on 3 Nov is 00:30 on 4 Nov in Dhaka (UTC+6).
    $instant = CarbonImmutable::parse('2026-11-03 18:30:00', 'UTC');
    DB::transaction(fn () => app(Ledger::class)->record(new LedgerEntry(
        T::SaleRevenue, Direction::In, Money::of('1000.00'), LedgerEntry::dhakaDateOf($instant), 'order', 7, false, true,
    )));

    $series = app(ProfitAndLoss::class)->series(ReportPeriod::of('2026-11-03', '2026-11-04'), ReportGranularity::Day);

    expect(array_map(fn (array $b): string => $b['totals']['net_revenue']->toDecimal(), $series['buckets']))
        ->toBe(['0.00', '1000.00']);
});

it('defaults to the last seven Dhaka days, so at 18:30 UTC "today" is already tomorrow', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-08 18:30:00', 'UTC'));
    $user = pnlStaff();

    $this->actingAs($user)->getJson('/api/v1/admin/finance/reports/pnl')
        ->assertOk()
        ->assertJsonPath('from', '2026-11-03')
        ->assertJsonPath('to', '2026-11-09');

    CarbonImmutable::setTestNow();
});

it('starts weeks on Saturday and clips the first and last week to the range', function (): void {
    // 6 Nov 2026 is a Friday, 7 Nov a Saturday.
    pnlEntry(T::SaleRevenue, Direction::In, '100.00', '2026-11-06', 'order', 1);
    pnlEntry(T::SaleRevenue, Direction::In, '200.00', '2026-11-07', 'order', 2);
    pnlEntry(T::SaleRevenue, Direction::In, '400.00', '2026-11-13', 'order', 3);
    pnlEntry(T::SaleRevenue, Direction::In, '800.00', '2026-11-14', 'order', 4);

    $series = app(ProfitAndLoss::class)->series(ReportPeriod::of('2026-11-02', '2026-11-15'), ReportGranularity::Week);

    expect(array_map(fn (array $b): array => [$b['from'], $b['to'], $b['days'], $b['totals']['net_revenue']->toDecimal()], $series['buckets']))->toBe([
        ['2026-11-02', '2026-11-06', 5, '100.00'],
        ['2026-11-07', '2026-11-13', 7, '600.00'],
        ['2026-11-14', '2026-11-15', 2, '800.00'],
    ])->and($series['totals']['net_revenue']->toDecimal())->toBe('1500.00');
});

it('splits months and years on their first day', function (): void {
    pnlEntry(T::SaleRevenue, Direction::In, '10.00', '2025-12-31', 'order', 1);
    pnlEntry(T::SaleRevenue, Direction::In, '20.00', '2026-01-01', 'order', 2);
    pnlEntry(T::SaleRevenue, Direction::In, '40.00', '2026-01-31', 'order', 3);
    pnlEntry(T::SaleRevenue, Direction::In, '80.00', '2026-02-01', 'order', 4);

    $monthly = app(ProfitAndLoss::class)->series(ReportPeriod::of('2025-12-15', '2026-02-10'), ReportGranularity::Month);
    $yearly = app(ProfitAndLoss::class)->series(ReportPeriod::of('2025-12-15', '2026-02-10'), ReportGranularity::Year);

    expect(array_map(fn (array $b): array => [$b['from'], $b['to'], $b['totals']['net_revenue']->toDecimal()], $monthly['buckets']))->toBe([
        ['2025-12-15', '2025-12-31', '10.00'],
        ['2026-01-01', '2026-01-31', '60.00'],
        ['2026-02-01', '2026-02-10', '80.00'],
    ])->and(array_map(fn (array $b): array => [$b['from'], $b['to'], $b['totals']['net_revenue']->toDecimal()], $yearly['buckets']))->toBe([
        ['2025-12-15', '2025-12-31', '10.00'],
        ['2026-01-01', '2026-02-10', '140.00'],
    ]);
});

it('reads a long range from the nightly rollup and the ledger only after the last rolled-up day', function (): void {
    pnlScenario();
    // Roll up through 6 Nov only: 7 and 8 Nov must come from the ledger.
    app(DailySummaryRollup::class)->run('2026-08-01', '2026-11-06');
    // A rollup row is the truth for its day: an entry added after the rollup ran is not in it.
    pnlEntry(T::OtherIncome, Direction::In, '55.00', '2026-11-02', 'other', 1);

    $long = ReportPeriod::of('2026-08-01', '2026-11-08');
    $statement = app(ProfitAndLoss::class)->statement($long, 'none');
    $ledgerOnly = ProfitAndLoss::totals(app(App\Domain\Finance\Reports\LedgerTotals::class)->recognisedByType($long));

    expect($statement['source'])->toBe('mixed')
        // The rollup matches the ledger for every day it covers (minus the late 55).
        ->and($statement['totals']['net_profit']->toDecimal())->toBe(bcsub($ledgerOnly['net_profit']->toDecimal(), '55.00', 2))
        ->and($statement['totals']['net_profit']->toDecimal())->toBe('397.00') // −602 + 999 (1 Nov)
        ->and(pnlAmounts($statement)['product_revenue'])->toBe('10249.00');
});

it('serves the statement, the series and both CSVs over the API with Money as strings', function (): void {
    pnlScenario();
    $user = pnlStaff();

    $this->actingAs($user)->getJson('/api/v1/admin/finance/reports/pnl?filter[from]=2026-11-02&filter[to]=2026-11-08&compare=none')
        ->assertOk()
        ->assertJsonPath('totals.net_profit', '-602.00')
        ->assertJsonPath('blocks.0.rows.0.key', 'product_revenue')
        ->assertJsonPath('blocks.0.rows.0.amount', '9250.00')
        ->assertJsonPath('cash.net', '1953.00')
        ->assertJsonPath('comparison', null)
        ->assertJsonPath('source', 'ledger');

    $this->actingAs($user)->getJson('/api/v1/admin/finance/reports/pnl/series?filter[from]=2026-11-02&filter[to]=2026-11-08&group=day')
        ->assertOk()
        ->assertJsonCount(7, 'buckets')
        ->assertJsonPath('buckets.1.from', '2026-11-03')
        ->assertJsonPath('totals.net_profit', '-602.00');

    $csv = $this->actingAs($user)->get('/api/v1/admin/finance/reports/pnl/export?filter[from]=2026-11-02&filter[to]=2026-11-08');
    $csv->assertOk();
    $body = $csv->streamedContent();
    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($body)->toContain('net_profit')
        ->and($body)->toContain('-602.00')
        ->and($body)->toContain('Facebook ads');

    $series = $this->actingAs($user)->get('/api/v1/admin/finance/reports/pnl/series/export?filter[from]=2026-11-02&filter[to]=2026-11-08&group=week');
    $series->assertOk();
    expect($series->streamedContent())->toContain('2026-11-07');
});

it('refuses unlisted parameters, filters, values and too many buckets with 400', function (string $query): void {
    $this->actingAs(pnlStaff())->getJson('/api/v1/admin/finance/reports/pnl/series?'.$query)
        ->assertStatus(400)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/bad-request');
})->with([
    'unknown param' => ['sort=net_profit'],
    'unknown filter' => ['filter[type]=cogs'],
    'bad group' => ['group=quarter'],
    'bad date' => ['filter[from]=2026-02-30&filter[to]=2026-03-01'],
    'reversed range' => ['filter[from]=2026-11-08&filter[to]=2026-11-01'],
    'too many days' => ['filter[from]=2024-01-01&filter[to]=2026-01-01&group=day'],
]);

it('keeps the P&L CSV byte-for-byte as before XLSX (format=csv is the default)', function (): void {
    ['ads' => $ads, 'sms' => $sms] = pnlScenario();
    $user = pnlStaff();
    $range = 'filter[from]=2026-11-02&filter[to]=2026-11-08';

    $default = $this->actingAs($user)->get("/api/v1/admin/finance/reports/pnl/export?{$range}");
    $default->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename=profit-and-loss-2026-11-02-to-2026-11-08.csv');
    $explicit = $this->actingAs($user)->get("/api/v1/admin/finance/reports/pnl/export?{$range}&format=csv");

    $block = static fn (int $n, string $name, array $lines): string => implode('', array_map(static fn (string $line): string => "{$n},{$name},{$line}\n", $lines));
    $expected = "\u{FEFF}block,block_name,kind,key,label,ledger_types,amount\n"
        .$block(1, '"What customers owed us"', ['line,product_revenue,"Product revenue",sale_revenue,9250.00', 'line,delivery_revenue,"Delivery charges collected",shipping_revenue,360.00', 'subtotal,gross_revenue,"Gross revenue",,9610.00', 'line,discounts,"Discounts and coupons",discount_given,-100.00', 'subtotal,net_revenue,"Net revenue",,9510.00'])
        .$block(2, '"What the toys cost us"', ['line,cogs,"Cost of goods sold",cogs,-5800.00', 'subtotal,gross_profit,"Gross profit",,3710.00'])
        .$block(3, '"What it cost to deliver and get paid"', ['line,courier_charges,"Courier delivery charges",courier_charge,-540.00', 'line,cod_fees,"COD collection fees",courier_cod_fee,-97.00', 'line,return_charges,"Courier return charges",courier_return_charge,-70.00', 'line,gateway_fees,"Payment gateway fees",gateway_fee,0.00', 'line,packaging,Packaging,packaging,-125.00', 'subtotal,profit_after_delivery,"Profit after delivery",,2878.00'])
        .$block(4, '"What went wrong"', ['line,refunds,"Refunds to customers",refund,-300.00', 'line,write_offs,"Damaged stock written off",inventory_write_off,-1280.00', 'subtotal,profit_after_losses,"Profit after losses",,1298.00'])
        .$block(5, '"What it cost to run the shop"', ['line,marketing,Advertising,marketing,-1400.00', 'line,salaries,Salaries,salary,0.00', 'line,rent,Rent,rent,0.00', 'line,utilities,Utilities,utility,0.00', 'line,other_expenses,"Other running costs",other_expense,-500.00', 'line,other_income,"Other income",other_income,0.00', 'subtotal,net_profit,"Net profit",,-602.00'])
        .",\"Expenses by category\",category,{$ads->id},\"Facebook ads\",marketing,-1400.00\n"
        .",\"Expenses by category\",category,{$sms->id},\"SMS credit\",operating,-500.00\n"
        .",\"Cash, not profit\",cash,cash_in,Received,,9153.00\n"
        .",\"Cash, not profit\",cash,cash_out,\"Paid out\",,-7200.00\n"
        .",\"Cash, not profit\",cash,cash_net,\"Net cash movement\",,1953.00\n";

    expect($default->streamedContent())->toBe($expected)
        ->and($explicit->streamedContent())->toBe($expected);

    $series = $this->actingAs($user)->get("/api/v1/admin/finance/reports/pnl/series/export?{$range}&group=week");
    $series->assertHeader('Content-Disposition', 'attachment; filename=profit-and-loss-by-week-2026-11-02-to-2026-11-08.csv');
    expect($series->streamedContent())->toBe(
        "\u{FEFF}from,to,days,product_revenue,delivery_revenue,discounts,cogs,courier_charges,cod_fees,return_charges,gateway_fees,packaging,refunds,write_offs,marketing,salaries,rent,utilities,other_expenses,other_income,gross_revenue,net_revenue,gross_profit,profit_after_delivery,profit_after_losses,net_profit,net_margin_percent\n"
        ."2026-11-02,2026-11-06,5,10450.00,360.00,-100.00,-6440.00,-540.00,-97.00,-70.00,0.00,-125.00,-300.00,-1280.00,0.00,0.00,0.00,0.00,-500.00,0.00,10810.00,10710.00,4270.00,3438.00,1858.00,1358.00,12.68\n"
        ."2026-11-07,2026-11-08,2,-1200.00,0.00,0.00,640.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,-1400.00,0.00,0.00,0.00,0.00,0.00,-1200.00,-1200.00,-560.00,-560.00,-560.00,-1960.00,163.33\n",
    );
});

it('exports the P&L statement and series as XLSX equal to the JSON, with Bangla labels and guarded text', function (): void {
    ['ads' => $ads] = pnlScenario();
    $ads->update(['name' => '=HYPERLINK("http://evil.example")']);
    $user = pnlStaff();
    $range = 'filter[from]=2026-11-02&filter[to]=2026-11-08';

    $json = $this->actingAs($user)->getJson("/api/v1/admin/finance/reports/pnl?{$range}&compare=none")->assertOk()->json();
    $response = $this->actingAs($user)->get("/api/v1/admin/finance/reports/pnl/export?{$range}&format=xlsx&locale=bn");
    $response->assertHeader('Content-Disposition', 'attachment; filename="profit-and-loss-2026-11-02-to-2026-11-08.xlsx"');
    $sheet = Xlsx::single($response);

    expect($sheet['name'])->toBe('লাভ-ক্ষতি')
        ->and($sheet['header'])->toBe(['block', 'block_name', 'kind', 'key', 'label', 'ledger_types', 'amount']);
    $rows = [];
    foreach ($sheet['rows'] as $row) {
        $rows[$row['kind'] === 'category' ? 'category:'.$row['key'] : $row['key']] = $row;
    }
    foreach ($json['blocks'] as $block) {
        foreach ($block['rows'] as $line) {
            expect($rows[$line['key']]['amount'])->toEqual(Xlsx::number($line['amount']))
                ->and($rows[$line['key']]['block'])->toBe($block['number']);
        }
    }
    expect($rows['product_revenue']['block_name'])->toBe('গ্রাহকদের কাছে আমাদের পাওনা')
        ->and($rows['net_profit']['label'])->toBe((string) __('reports.pnl.line.net_profit', [], 'bn'))
        ->and($rows['net_profit']['amount'])->toEqual(-602)
        ->and($rows['category:'.$ads->id]['label'])->toBe("'=HYPERLINK(\"http://evil.example\")")
        ->and($rows['cash_in']['amount'])->toEqual(Xlsx::number($json['cash']['in']))
        ->and($rows['cash_out']['amount'])->toEqual(-1 * Xlsx::number($json['cash']['out']))
        ->and($rows['cash_net']['amount'])->toEqual(Xlsx::number($json['cash']['net']));

    $seriesJson = $this->actingAs($user)->getJson("/api/v1/admin/finance/reports/pnl/series?{$range}&group=week")->assertOk()->json();
    $series = Xlsx::single($this->actingAs($user)->get("/api/v1/admin/finance/reports/pnl/series/export?{$range}&group=week&format=xlsx&locale=en"));

    expect($series['name'])->toBe('P&L by week')
        ->and(array_column($series['rows'], 'from'))->toBe(array_column($seriesJson['buckets'], 'from'));
    foreach ($seriesJson['buckets'] as $i => $bucket) {
        foreach ([...$bucket['lines'], ...$bucket['totals']] as $key => $amount) {
            expect($series['rows'][$i][$key])->toEqual(Xlsx::number($amount));
        }
        expect($series['rows'][$i]['days'])->toBe($bucket['days'])
            ->and($series['rows'][$i]['net_margin_percent'])->toEqual(Xlsx::number($bucket['net_margin_percent']));
    }
    expect(Xlsx::single($this->actingAs($user)->get("/api/v1/admin/finance/reports/pnl/series/export?{$range}&group=day&format=xlsx&locale=bn"))['name'])->toBe('দৈনিক লাভ-ক্ষতি');
});

/*
 * Premium P&L (B · 31 premium, Phase 10): block totals and shares, line occurrences, the
 * biggest costs, the reconciliation difference, the open pipeline by status and the
 * item-cost lock. Every new figure reconciles with financial_transactions to the poisha.
 */

/** Σ in − Σ out over recognised entries of the given types in the week, straight from the table. */
function pnlLedgerNet(array $types, string $from = '2026-11-02', string $to = '2026-11-08'): string
{
    $sum = static fn (string $direction): string => (string) (DB::table('financial_transactions')
        ->where('is_recognised_revenue', true)
        ->whereIn('type', $types)
        ->where('direction', $direction)
        ->whereBetween('occurred_on', [$from, $to])
        ->sum('amount') ?: '0');

    return App\Domain\Finance\Reports\ReportMoney::of($sum('in'))->minus(App\Domain\Finance\Reports\ReportMoney::of($sum('out')))->toDecimal();
}

it('gives every block a total and every row a share of net revenue, reconciled with the ledger to the poisha', function (): void {
    pnlScenario();

    $statement = app(ProfitAndLoss::class)->statement(ReportPeriod::of('2026-11-02', '2026-11-08'), 'none');
    $blocks = collect($statement['blocks'])->keyBy('key');

    expect($blocks->map(fn (array $b): string => $b['total']->toDecimal())->all())->toBe([
        'revenue' => '9510.00', 'goods' => '-5800.00', 'delivery' => '-832.00', 'losses' => '-1580.00', 'running' => '-1900.00',
    ])->and($blocks->map(fn (array $b): ?string => $b['percent_of_revenue'])->all())->toBe([
        'revenue' => '100.00', 'goods' => '-60.99', 'delivery' => '-8.75', 'losses' => '-16.61', 'running' => '-19.98',
    ]);

    // Each block total is the ledger's own net over that block's types, to the poisha…
    $sum = Money::zero();
    foreach (App\Domain\Finance\Reports\PnlLayout::blocks() as $layout) {
        $types = [];
        foreach ($layout['rows'] as $row) {
            if ($row['kind'] === 'line') {
                array_push($types, ...array_map(fn (T $t): string => $t->value, $row['types']));
            }
        }
        expect($blocks[$layout['key']]['total']->toDecimal())->toBe(pnlLedgerNet($types));
        $sum = $sum->plus($blocks[$layout['key']]['total']);
    }
    // …and the five add up to net profit, which is the ledger's net over every P&L type.
    expect($sum->toDecimal())->toBe('-602.00')
        ->and($statement['totals']['net_profit']->toDecimal())->toBe(pnlLedgerNet(App\Domain\Finance\Reports\PnlLayout::pnlTypeValues()));

    $rows = collect($statement['blocks'])->flatMap(fn (array $b) => $b['rows'])->keyBy('key');
    expect($rows['product_revenue']['percent_of_revenue'])->toBe('97.27')
        ->and($rows['cogs']['percent_of_revenue'])->toBe('-60.99')
        ->and($rows['net_revenue']['percent_of_revenue'])->toBe('100.00')
        ->and($rows['net_profit']['percent_of_revenue'])->toBe('-6.33')
        // Occurrences count charges, not their reversals: order 4's sale and COGS each count once.
        ->and($rows->filter(fn (array $r) => $r['kind'] === 'line')->map(fn (array $r): int => $r['occurrences'])->all())->toBe([
            'product_revenue' => 3, 'delivery_revenue' => 2, 'discounts' => 1, 'cogs' => 3,
            'courier_charges' => 3, 'cod_fees' => 2, 'return_charges' => 1, 'gateway_fees' => 0, 'packaging' => 3,
            'refunds' => 1, 'write_offs' => 1,
            'marketing' => 1, 'salaries' => 0, 'rent' => 0, 'utilities' => 0, 'other_expenses' => 1, 'other_income' => 0,
        ]);

    expect(array_map(fn (array $c): array => [$c['key'], $c['block'], $c['cost']->toDecimal(), $c['relative_percent']], $statement['biggest_costs']))->toBe([
        ['marketing', 'running', '1400.00', '100.00'],
        ['write_offs', 'losses', '1280.00', '91.43'],
        ['courier_charges', 'delivery', '540.00', '38.57'],
        ['other_expenses', 'running', '500.00', '35.71'],
    ])->and($statement['reconciliation']['difference']->toDecimal())->toBe('0.00');
});

it('reports by how much the statement and the ledger rebuild differ when they do not tie up', function (): void {
    pnlScenario();
    app(DailySummaryRollup::class)->run('2026-08-01', '2026-11-06');
    // Written after the nightly rollup ran: in the ledger, not in the rolled-up day.
    pnlEntry(T::OtherIncome, Direction::In, '55.00', '2026-11-02', 'other', 1);

    $statement = app(ProfitAndLoss::class)->statement(ReportPeriod::of('2026-08-01', '2026-11-08'), 'none');

    expect($statement['reconciliation']['reconciled'])->toBeFalse()
        ->and($statement['totals']['net_profit']->toDecimal())->toBe('397.00')
        ->and($statement['reconciliation']['total']->toDecimal())->toBe(pnlLedgerNet(App\Domain\Finance\Reports\PnlLayout::pnlTypeValues(), '2026-08-01'))
        ->and($statement['reconciliation']['difference']->toDecimal())->toBe('-55.00');
});

it('splits the open pipeline by status with order values', function (): void {
    App\Domain\Ordering\Models\Order::factory()->status(App\Domain\Ordering\Enums\OrderStatus::Pending)->total('1000.00')->create();
    App\Domain\Ordering\Models\Order::factory()->status(App\Domain\Ordering\Enums\OrderStatus::Pending)->total('7170.00')->create();
    App\Domain\Ordering\Models\Order::factory()->status(App\Domain\Ordering\Enums\OrderStatus::Shipped)->total('2570.00')->create();
    App\Domain\Ordering\Models\Order::factory()->status(App\Domain\Ordering\Enums\OrderStatus::Delivered)->total('999.00')->create();

    $open = app(ProfitAndLoss::class)->statement(ReportPeriod::of('2026-11-02', '2026-11-08'), 'none')['open_orders'];

    expect($open['count'])->toBe(3)
        ->and($open['value']->toDecimal())->toBe('10740.00')
        ->and(array_map(fn (array $s): array => [$s['status'], $s['count'], $s['value']->toDecimal()], $open['by_status']))->toBe([
            ['pending', 2, '8170.00'],
            ['shipped', 1, '2570.00'],
        ]);
});

it('serves the premium fields and withholds the cost-of-goods line without catalog.view_cost', function (): void {
    pnlScenario();
    $url = '/api/v1/admin/finance/reports/pnl?filter[from]=2026-11-02&filter[to]=2026-11-08&compare=none';

    $this->actingAs(pnlStaff())->getJson($url)
        ->assertOk()
        ->assertJsonPath('item_costs_visible', true)
        ->assertJsonPath('blocks.1.rows.0.amount', '-5800.00')
        ->assertJsonPath('blocks.1.total', '-5800.00')
        ->assertJsonPath('blocks.1.percent_of_revenue', '-60.99')
        ->assertJsonPath('blocks.0.rows.0.occurrences', 3)
        ->assertJsonPath('biggest_costs.0.cost', '1400.00')
        ->assertJsonPath('reconciliation.difference', '0.00')
        ->assertJsonPath('open_orders.by_status', []);

    // Roles are editable (rule 20b): finance.view without catalog.view_cost. Owner decision
    // 2026-11-16: cost of goods and every subtotal it can be backed out of are withheld.
    $clerk = User::factory()->create()->refresh();
    $clerk->givePermissionTo(App\Models\Enums\Permission::FinanceView->value);
    $json = $this->actingAs($clerk)->getJson(str_replace('compare=none', 'compare=previous_period', $url))
        ->assertOk()
        ->assertJsonPath('item_costs_visible', false)
        ->assertJsonPath('blocks.1.rows.0.key', 'cogs')
        ->assertJsonPath('blocks.1.rows.0.amount', null)
        ->assertJsonPath('blocks.1.rows.0.percent_of_revenue', null)
        ->assertJsonPath('blocks.1.rows.0.occurrences', null)
        ->assertJsonPath('blocks.1.total', null)
        ->assertJsonPath('blocks.1.percent_of_revenue', null)
        ->assertJsonPath('blocks.1.rows.1.amount', null)
        ->assertJsonPath('blocks.1.rows.1.percent_of_revenue', null)
        ->assertJsonPath('blocks.2.rows.5.key', 'profit_after_delivery')
        ->assertJsonPath('blocks.2.rows.5.amount', null)
        ->assertJsonPath('blocks.3.rows.2.amount', null)
        ->assertJsonPath('totals.gross_profit', null)
        ->assertJsonPath('totals.profit_after_delivery', null)
        ->assertJsonPath('totals.profit_after_losses', null)
        ->assertJsonPath('gross_margin_percent', null)
        ->assertJsonPath('comparison.totals.gross_profit', null)
        ->assertJsonPath('comparison.deltas.gross_profit', null)
        // Net revenue, the other lines and net profit stay exact.
        ->assertJsonPath('totals.net_revenue', '9510.00')
        ->assertJsonPath('blocks.2.total', '-832.00')
        ->assertJsonPath('totals.net_profit', '-602.00')
        ->assertJsonPath('inventory_at_cost', null)
        ->json();
    // (The reconciliation's order-by-order sum is 3,710 here only because this scenario's
    // order-scoped entries happen to be revenue and COGS; it carries no more than net profit.)
    unset($json['reconciliation']);
    $body = json_encode($json);
    foreach (['5800', '3710', '2878', '1298', '39.01'] as $withheld) {
        expect($body)->not->toContain($withheld);
    }

    // The series follows the same rule.
    $series = $this->actingAs($clerk)->getJson('/api/v1/admin/finance/reports/pnl/series?filter[from]=2026-11-02&filter[to]=2026-11-08&group=week')
        ->assertOk()
        ->assertJsonPath('item_costs_visible', false)
        ->assertJsonPath('totals.gross_profit', null)
        ->assertJsonPath('buckets.0.lines.cogs', null)
        ->assertJsonPath('buckets.0.totals.profit_after_losses', null)
        ->assertJsonPath('totals.net_profit', '-602.00')
        ->json();
    expect(json_encode($series))->not->toContain('3710')->not->toContain('-5800');

    // No finance.view at all: still 403.
    $none = User::factory()->create()->refresh();
    $none->givePermissionTo(App\Models\Enums\Permission::CatalogViewCost->value);
    $this->actingAs($none)->getJson($url)->assertForbidden();
});

it('leaves the withheld rows and columns out of the P&L files for a reader without catalog.view_cost', function (): void {
    ['ads' => $ads, 'sms' => $sms] = pnlScenario();
    (new RolesAndPermissionsSeeder)->run();
    $clerk = User::factory()->create()->refresh();
    $clerk->givePermissionTo([App\Models\Enums\Permission::FinanceView->value, App\Models\Enums\Permission::FinanceExport->value]);
    $range = 'filter[from]=2026-11-02&filter[to]=2026-11-08';

    $block = static fn (int $n, string $name, array $lines): string => implode('', array_map(static fn (string $line): string => "{$n},{$name},{$line}\n", $lines));
    $expected = "\u{FEFF}block,block_name,kind,key,label,ledger_types,amount\n"
        .$block(1, '"What customers owed us"', ['line,product_revenue,"Product revenue",sale_revenue,9250.00', 'line,delivery_revenue,"Delivery charges collected",shipping_revenue,360.00', 'subtotal,gross_revenue,"Gross revenue",,9610.00', 'line,discounts,"Discounts and coupons",discount_given,-100.00', 'subtotal,net_revenue,"Net revenue",,9510.00'])
        .$block(3, '"What it cost to deliver and get paid"', ['line,courier_charges,"Courier delivery charges",courier_charge,-540.00', 'line,cod_fees,"COD collection fees",courier_cod_fee,-97.00', 'line,return_charges,"Courier return charges",courier_return_charge,-70.00', 'line,gateway_fees,"Payment gateway fees",gateway_fee,0.00', 'line,packaging,Packaging,packaging,-125.00'])
        .$block(4, '"What went wrong"', ['line,refunds,"Refunds to customers",refund,-300.00', 'line,write_offs,"Damaged stock written off",inventory_write_off,-1280.00'])
        .$block(5, '"What it cost to run the shop"', ['line,marketing,Advertising,marketing,-1400.00', 'line,salaries,Salaries,salary,0.00', 'line,rent,Rent,rent,0.00', 'line,utilities,Utilities,utility,0.00', 'line,other_expenses,"Other running costs",other_expense,-500.00', 'line,other_income,"Other income",other_income,0.00', 'subtotal,net_profit,"Net profit",,-602.00'])
        .",\"Expenses by category\",category,{$ads->id},\"Facebook ads\",marketing,-1400.00\n"
        .",\"Expenses by category\",category,{$sms->id},\"SMS credit\",operating,-500.00\n"
        .",\"Cash, not profit\",cash,cash_in,Received,,9153.00\n"
        .",\"Cash, not profit\",cash,cash_out,\"Paid out\",,-7200.00\n"
        .",\"Cash, not profit\",cash,cash_net,\"Net cash movement\",,1953.00\n";

    expect($this->actingAs($clerk)->get("/api/v1/admin/finance/reports/pnl/export?{$range}")->assertOk()->streamedContent())->toBe($expected);

    $sheet = Xlsx::single($this->actingAs($clerk)->get("/api/v1/admin/finance/reports/pnl/export?{$range}&format=xlsx&locale=en"));
    expect(array_column($sheet['rows'], 'key'))->not->toContain('cogs')->not->toContain('gross_profit')->not->toContain('profit_after_delivery')->not->toContain('profit_after_losses');

    $series = $this->actingAs($clerk)->get("/api/v1/admin/finance/reports/pnl/series/export?{$range}&group=week")->assertOk()->streamedContent();
    expect($series)->toBe(
        "\u{FEFF}from,to,days,product_revenue,delivery_revenue,discounts,courier_charges,cod_fees,return_charges,gateway_fees,packaging,refunds,write_offs,marketing,salaries,rent,utilities,other_expenses,other_income,gross_revenue,net_revenue,net_profit,net_margin_percent\n"
        ."2026-11-02,2026-11-06,5,10450.00,360.00,-100.00,-540.00,-97.00,-70.00,0.00,-125.00,-300.00,-1280.00,0.00,0.00,0.00,0.00,-500.00,0.00,10810.00,10710.00,1358.00,12.68\n"
        ."2026-11-07,2026-11-08,2,-1200.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,-1400.00,0.00,0.00,0.00,0.00,0.00,-1200.00,-1200.00,-1960.00,163.33\n",
    );
});
