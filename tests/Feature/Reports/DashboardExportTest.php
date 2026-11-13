<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType as T;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Reporting\Exports\DashboardSheets;
use App\Domain\Reporting\Queries\Dashboard\DashboardReport;
use App\Domain\Reporting\Queries\Dashboard\DashboardScope;
use App\Domain\Reporting\Support\DateRange;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Feature\Finance\FinanceKit;

/*
 * Dashboard export (P8, Figma B · 01 Export button): CSV + XLSX of the same figures the JSON
 * returns, ledger figures equal to the ledger to the poisha, blocks the viewer may not see left
 * out (never zero), no PII, Bangla labels, the 403 and the 400s.
 */

/** $export grants reports.export on top of the role, so block visibility can be tested per role. */
function dashExportUser(?Role $role, bool $export = true): User
{
    (new RolesAndPermissionsSeeder)->run();
    app('auth')->forgetGuards();
    $factory = User::factory();
    $user = ($role === null ? $factory : $factory->withRole($role))->create();
    if ($export && $role !== null) {
        $user->givePermissionTo(Permission::ReportsExport->value);
    }
    $user = $user->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

function dashExportLedger(T $type, Direction $direction, string $amount, string $on, string $sourceType, int $sourceId, bool $recognised = true, bool $cash = false): void
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

/** Σ in − Σ out straight from financial_transactions. */
function dashExportLedgerNet(string $from, string $to, array $types, ?bool $recognised = true, ?bool $cash = null): string
{
    $net = Money::zero();
    $rows = DB::table('financial_transactions')
        ->whereBetween('occurred_on', [$from, $to])
        ->whereIn('type', array_map(static fn (T $t): string => $t->value, $types))
        ->when($recognised !== null, static fn ($q) => $q->where('is_recognised_revenue', $recognised))
        ->when($cash !== null, static fn ($q) => $q->where('is_cash_movement', $cash))
        ->get(['direction', 'amount']);
    foreach ($rows as $row) {
        $amount = Money::of(sprintf('%.2F', round((float) $row->amount, 2)));
        $net = $row->direction === 'in' ? $net->plus($amount) : $net->minus($amount);
    }

    return $net->toDecimal();
}

/**
 * A CSV split back into sections: title => list of rows (header first). Spreadsheet writes a
 * title row only when there are several sections; a lone section is returned under 'Summary'
 * (the only sheet that is always present).
 *
 * @return array<string, list<list<string>>>
 */
function dashExportCsv(string $csv): array
{
    $csv = str_replace("\u{FEFF}", '', $csv);
    $sections = [];
    foreach (preg_split("/\n\n/", rtrim($csv, "\n")) ?: [] as $block) {
        $lines = array_map(static fn (string $line): array => array_map(strval(...), str_getcsv($line, ',', '"', '')), explode("\n", $block));
        $title = count($lines[0]) === 1 ? (string) array_shift($lines)[0] : 'Summary';
        $sections[$title] = $lines;
    }

    return $sections;
}

/** @return array<string, list<list<mixed>>> */
function dashExportXlsx(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'dash-').'.xlsx';
    file_put_contents($path, $bytes);
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        $sheets[$sheet->getName()] = $rows;
    }
    $reader->close();
    @unlink($path);

    return $sheets;
}

/** Summary rows keyed by metric key: key => [value, previous, change]. */
function dashExportSummary(array $rows): array
{
    $out = [];
    foreach (array_slice($rows, 1) as $row) {
        $out[$row[0]] = [$row[2], $row[3], $row[4]];
    }

    return $out;
}

/** A month of delivered orders with ledger entries, a cancelled placed order and a low-stock variant. */
function dashExportSeedMonth(): Product
{
    $category = Category::factory()->create(['name' => 'Puzzles & Brain Games', 'name_bn' => 'পাজল ও মস্তিষ্কের খেলা']);
    $product = Product::factory()->create(['name' => 'Wooden Alphabet Puzzle BN+EN', 'name_bn' => 'কাঠের বর্ণমালা পাজল', 'primary_category_id' => $category->id]);
    $customer = Customer::factory()->create(['name' => 'Shahriar Kabir', 'phone' => '+8801712345318', 'email' => 'shahriar@example.com']);

    foreach (['2026-10-01', '2026-10-07', '2026-10-15', '2026-10-22', '2026-10-31', '2026-09-30', '2026-11-01'] as $i => $day) {
        test()->travelTo(CarbonImmutable::parse("{$day} 08:00:00", 'Asia/Dhaka'));
        $order = Order::factory()->status(OrderStatus::Shipped)->create([
            'customer_id' => $customer->id,
            'subtotal' => '1250.35',
            'discount_total' => $i % 2 === 0 ? '50.10' : '0.00',
            'coupon_discount' => $i % 2 === 0 ? '50.10' : '0.00',
            'shipping_charge' => '70.00',
            'grand_total' => $i % 2 === 0 ? '1270.25' : '1320.35',
            'due_total' => $i % 2 === 0 ? '1270.25' : '1320.35',
            'placed_at' => now()->subHours(3),
            'shipped_at' => now(),
            'shipping_address' => 'House 7, Road 3, Mirpur 10',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id, 'product_id' => $product->id, 'unit_price' => '1250.35', 'quantity' => 1, 'discount' => '0.00',
            'line_total' => '1250.35', 'cost_price_snapshot' => '611.3333',
        ]);
        FinanceKit::deliver($order->refresh());
        dashExportLedger(T::CourierCharge, Direction::Out, '61.57', $day, 'shipment', 1000 + $i, cash: true);
        if ($i % 2 === 0) {
            dashExportLedger(T::CodReceived, Direction::In, '1190.01', $day, 'manual_cod_receipt', 2000 + $i, recognised: false, cash: true);
        }
    }
    dashExportLedger(T::Marketing, Direction::Out, '999.99', '2026-10-20', 'expense', 1, cash: true);
    // Placed in range, then cancelled: still a placed figure, never revenue.
    Order::factory()->status(OrderStatus::Cancelled)->total('777.77')->create(['customer_id' => $customer->id, 'placed_at' => CarbonImmutable::parse('2026-10-12 06:00:00', 'UTC')]);

    $variant = $product->variants()->firstOrFail();
    DB::table('stock_levels')->updateOrInsert(
        ['variant_id' => $variant->id, 'location_id' => StockLocation::defaultId()],
        ['on_hand' => 8, 'reserved' => 2, 'reorder_point' => 12, 'created_at' => now(), 'updated_at' => now()],
    );

    test()->travelTo(CarbonImmutable::parse('2026-11-13 12:00:00', 'Asia/Dhaka'));

    return $product;
}

const DASH_EXPORT_OCT = 'range=custom&from=2026-10-01&to=2026-10-31';

it('exports the same figures the JSON returns, and the ledger to the poisha', function (): void {
    dashExportUser(Role::Manager);
    dashExportSeedMonth();

    $json = $this->getJson('/api/v1/admin/dashboard?'.DASH_EXPORT_OCT)->assertOk()->json();
    $response = $this->get('/api/v1/admin/dashboard/export?'.DASH_EXPORT_OCT.'&format=csv&locale=en')->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="dashboard-2026-10-01-to-2026-10-31-exported-2026-11-13.csv"');
    $csv = $response->streamedContent();
    $sections = dashExportCsv($csv);

    expect($csv)->toStartWith("\u{FEFF}")
        ->and(array_keys($sections))->toBe(['Summary', 'Trend', 'Orders by status (now)', 'Revenue by category', 'Best sellers (placed)', 'Low stock (now)']);

    $summary = dashExportSummary($sections['Summary']);
    $k = $json['kpis'];
    expect($summary['range'])->toBe(['2026-10-01 – 2026-10-31', '2026-08-31 – 2026-09-30', ''])
        ->and($summary['orders_placed'])->toBe([(string) $k['orders_placed']['count'], (string) $k['orders_placed']['previous_count'], (string) $k['orders_placed']['count_delta_percent']])
        ->and($summary['placed_value'])->toBe([$k['orders_placed']['value'], $k['orders_placed']['previous_value'], (string) $k['orders_placed']['value_delta_percent']])
        ->and($summary['average_order_value'][0])->toBe($k['average_order_value']['value'])
        ->and($summary['recognised_revenue'])->toBe([$k['recognised_revenue']['value'], $k['recognised_revenue']['previous'], (string) $k['recognised_revenue']['delta_percent']])
        ->and($summary['gross_profit'][0])->toBe($k['gross_profit']['value'])
        ->and($summary['gross_margin_percent'][0])->toBe((string) $k['gross_profit']['margin_percent'])
        ->and($summary['net_profit'][0])->toBe($k['net_profit']['value'])
        ->and($summary['net_margin_percent'][0])->toBe((string) $k['net_profit']['margin_percent'])
        ->and($summary['cash_collected'][0])->toBe($k['cash_collected']['value'])
        ->and($summary['customers'][0])->toBe((string) $k['customers']['customers'])
        ->and($summary['new_customers'][0])->toBe((string) $k['customers']['new'])
        ->and($summary['low_stock'][0])->toBe((string) $k['low_stock']['low'])
        ->and($summary['out_of_stock'][0])->toBe((string) $k['low_stock']['out'])
        ->and($summary['open_orders'])->toBe([(string) $json['status']['open']['count'], '', ''])
        ->and($summary['open_orders_value'][0])->toBe($json['status']['open']['value']);

    // Placed (6 orders incl. the cancelled one) is labelled and kept apart from recognised revenue.
    expect($summary['orders_placed'][0])->toBe('6')
        ->and($summary['placed_value'][0])->toBe('7229.22'); // 3 × 1270.25 + 2 × 1320.35 + 777.77 (the 30 Sep placing is out of range)

    // Ledger figures equal the ledger, to the poisha.
    $revenueTypes = [T::SaleRevenue, T::ShippingRevenue, T::DiscountGiven];
    $grossTypes = [...$revenueTypes, T::Cogs];
    $allPnl = [...$grossTypes, T::CourierCharge, T::CourierCodFee, T::CourierReturnCharge, T::GatewayFee, T::Packaging, T::Refund, T::InventoryWriteOff, T::Marketing, T::Salary, T::Rent, T::Utility, T::OtherExpense, T::OtherIncome];
    expect($summary['recognised_revenue'][0])->toBe(dashExportLedgerNet('2026-10-01', '2026-10-31', $revenueTypes))
        ->and($summary['recognised_revenue'][0])->toBe('6451.45') // 5 × 1320.35 − 3 × 50.10
        ->and($summary['gross_profit'][0])->toBe(dashExportLedgerNet('2026-10-01', '2026-10-31', $grossTypes))
        ->and($summary['net_profit'][0])->toBe(dashExportLedgerNet('2026-10-01', '2026-10-31', $allPnl))
        ->and($summary['cash_collected'][0])->toBe(dashExportLedgerNet('2026-10-01', '2026-10-31', [T::CodReceived], null, true))
        ->and($summary['cash_collected'][0])->toBe('3570.03');

    // Trend: one row per JSON bucket, the same cells; the ledger columns sum back to the totals.
    $trend = $sections['Trend'];
    $header = array_shift($trend);
    $byKey = collect($trend)->map(fn (array $row): array => array_combine($header, $row))->keyBy('bucket_start');
    expect($header)->toBe(['bucket_start', 'bucket_end', 'placed_orders', 'placed_value', 'units_placed', 'previous_bucket_start', 'previous_placed_orders', 'previous_placed_value', 'previous_units_placed', 'recognised_revenue', 'gross_profit', 'net_profit', 'cash_collected', 'new_customers'])
        ->and($byKey)->toHaveCount(31);
    foreach ($json['trend']['buckets'] as $bucket) {
        expect($byKey[$bucket['key']]['placed_value'])->toBe($bucket['placed_value'])
            ->and($byKey[$bucket['key']]['placed_orders'])->toBe((string) $bucket['placed_count'])
            ->and($byKey[$bucket['key']]['recognised_revenue'])->toBe($bucket['recognised_revenue']);
    }
    $sum = fn (string $column): string => $byKey->reduce(fn (Money $c, array $r): Money => $c->plus(Money::of($r[$column])), Money::zero())->toDecimal();
    expect($sum('recognised_revenue'))->toBe($summary['recognised_revenue'][0])
        ->and($sum('net_profit'))->toBe($summary['net_profit'][0])
        ->and($sum('cash_collected'))->toBe($summary['cash_collected'][0])
        ->and($sum('placed_value'))->toBe($summary['placed_value'][0]);

    // Status strip, categories, best sellers, low stock: the JSON's rows.
    $status = collect(array_slice($sections['Orders by status (now)'], 1))->mapWithKeys(fn (array $r): array => [$r[0] => (int) $r[2]]);
    expect($status->all())->toBe($json['status']['counts']);

    $categories = array_slice($sections['Revenue by category'], 1);
    expect($categories[0])->toBe(['category', (string) $json['categories']['slices'][0]['category_id'], 'Puzzles & Brain Games', 'পাজল ও মস্তিষ্কের খেলা', $json['categories']['slices'][0]['amount'], (string) $json['categories']['slices'][0]['share_percent']])
        ->and(end($categories))->toBe(['total', '', 'Total', '', $json['categories']['total'], '']);

    $best = array_slice($sections['Best sellers (placed)'], 1);
    expect($best[0][2])->toBe($json['best_sellers']['rows'][0]['name'])
        ->and($best[0][5])->toBe((string) $json['best_sellers']['rows'][0]['units'])
        ->and($best[0][6])->toBe($json['best_sellers']['rows'][0]['value'])
        ->and(end($best))->toBe(['total', (string) $json['best_sellers']['products'], 'All products placed in the period', '', '', (string) $json['best_sellers']['units'], $json['best_sellers']['value']]);

    $low = array_slice($sections['Low stock (now)'], 1);
    expect($low)->toHaveCount(count($json['low_stock']['rows']))
        ->and($low[0][0])->toBe((string) $json['low_stock']['rows'][0]['variant_id'])
        ->and(array_slice($low[0], 6))->toBe(['8', '2', '6', '12']);

    FinanceKit::assertFinanceInvariant();
});

it('streams an XLSX that opens, with Bangla sheet names and labels and numeric money cells', function (): void {
    dashExportUser(Role::Manager);
    dashExportSeedMonth();

    $response = $this->get('/api/v1/admin/dashboard/export?'.DASH_EXPORT_OCT.'&format=xlsx&locale=bn')->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('Content-Disposition', 'attachment; filename="dashboard-2026-10-01-to-2026-10-31-exported-2026-11-13.xlsx"');
    $sheets = dashExportXlsx($response->streamedContent());

    expect(array_keys($sheets))->toBe(['সারসংক্ষেপ', 'প্রবণতা', 'অবস্থা অনুযায়ী অর্ডার (এখন)', 'ক্যাটাগরি অনুযায়ী আয়', 'সেরা বিক্রিত (দেওয়া)', 'কম স্টক (এখন)']);

    $summary = collect(array_slice($sheets['সারসংক্ষেপ'], 1))->keyBy(0);
    expect($summary['recognised_revenue'][1])->toBe('স্বীকৃত নিট আয় (ডেলিভারি হওয়া)')
        ->and($summary['cash_collected'][1])->toContain('নগদ, আয় নয়')
        ->and($summary['recognised_revenue'][2])->toEqual(6451.45)
        ->and($summary['orders_placed'][2])->toBe(6)
        // Dates and numerals stay Western (rule 36).
        ->and($summary['range'][2])->toBe('2026-10-01 – 2026-10-31')
        ->and(json_encode($sheets, JSON_UNESCAPED_UNICODE))->not->toMatch('/[০-৯]/u');

    $categories = $sheets['ক্যাটাগরি অনুযায়ী আয়'];
    expect($categories[1][3])->toBe('পাজল ও মস্তিষ্কের খেলা')
        ->and(end($categories)[2])->toBe('মোট');
    $status = collect(array_slice($sheets['অবস্থা অনুযায়ী অর্ডার (এখন)'], 1))->keyBy(0);
    expect($status['delivered'][1])->toBe('ডেলিভারি হয়েছে');
});

it('leaves out every block the viewer may not see, never writing it as zero', function (Role $role, array $sheets, bool $finance, bool $customers): void {
    dashExportUser($role);
    dashExportSeedMonth();

    $json = $this->getJson('/api/v1/admin/dashboard?'.DASH_EXPORT_OCT)->assertOk()->json();
    $sections = dashExportCsv($this->get('/api/v1/admin/dashboard/export?'.DASH_EXPORT_OCT.'&locale=en')->assertOk()->streamedContent());
    $summary = dashExportSummary($sections['Summary']);

    expect(array_keys($sections))->toBe($sheets)
        ->and(array_key_exists('recognised_revenue', $summary))->toBe($finance)
        ->and(array_key_exists('net_profit', $summary))->toBe($finance)
        ->and(array_key_exists('cash_collected', $summary))->toBe($finance)
        ->and(array_key_exists('customers', $summary))->toBe($customers)
        // The export hides exactly what the JSON hides.
        ->and($json['kpis']['recognised_revenue'] !== null)->toBe($finance)
        ->and($json['kpis']['customers'] !== null)->toBe($customers);

    if (isset($sections['Trend'])) {
        expect(in_array('recognised_revenue', $sections['Trend'][0], true))->toBe($finance)
            ->and(in_array('new_customers', $sections['Trend'][0], true))->toBe($customers);
    }
})->with([
    'order_operator' => [Role::OrderOperator, ['Summary', 'Trend', 'Orders by status (now)', 'Best sellers (placed)', 'Low stock (now)'], false, true],
    'inventory_staff' => [Role::InventoryStaff, ['Summary', 'Trend', 'Orders by status (now)', 'Best sellers (placed)', 'Low stock (now)'], false, false],
    'accountant' => [Role::Accountant, ['Summary', 'Trend', 'Orders by status (now)', 'Revenue by category', 'Best sellers (placed)', 'Low stock (now)'], true, true],
    'content_editor' => [Role::ContentEditor, ['Summary'], false, false],
]);

it('gives a role with no dashboard blocks only the period, not zeros', function (): void {
    dashExportUser(Role::ContentEditor);
    $csv = $this->get('/api/v1/admin/dashboard/export?format=csv')->assertOk()->streamedContent();

    expect(array_keys(dashExportSummary(dashExportCsv($csv)['Summary'])))->toBe(['range', 'granularity', 'exported_at']);
});

it('carries no customer PII, activity or needs-attention items', function (): void {
    dashExportUser(Role::SuperAdmin);
    dashExportSeedMonth();

    $csv = $this->get('/api/v1/admin/dashboard/export?'.DASH_EXPORT_OCT)->assertOk()->streamedContent();

    expect($csv)->not->toContain('Shahriar')
        ->not->toContain('1712345318')
        ->not->toContain('shahriar@example.com')
        ->not->toContain('House 7')
        ->not->toContain('Mirpur')
        ->not->toContain('TMB-');
});

it('guards text cells against formula injection', function (): void {
    dashExportUser(Role::Manager);
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'Asia/Dhaka'));
    $product = Product::factory()->create(['name' => '=HYPERLINK("http://evil.example")']);
    $order = Order::factory()->status(OrderStatus::Pending)->total('500.00')->create(['customer_id' => Customer::factory(), 'placed_at' => CarbonImmutable::parse('2026-11-05 06:00:00', 'UTC')]);
    OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'unit_price' => '500.00', 'quantity' => 1, 'discount' => '0.00', 'line_total' => '500.00']);

    $csv = $this->get('/api/v1/admin/dashboard/export')->assertOk()->streamedContent();

    expect($csv)->toContain("'=HYPERLINK")->not->toContain(',=HYPERLINK');
});

it('builds sheets from the report array alone, with an explicit export time', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'Asia/Dhaka'));
    $report = app(DashboardReport::class)->build(DateRange::fromPreset('7d', null, null), DashboardScope::everything());

    $sheets = DashboardSheets::build($report, 'en', '2026-11-08 12:00');

    expect($sheets[0]->rows[2])->toBe(['exported_at', 'Exported at (Dhaka time)', '2026-11-08 12:00', '', '']);
});

it('returns 401 for a guest and 403 without dashboard.view', function (): void {
    $this->get('/api/v1/admin/dashboard/export', ['Accept' => 'application/json'])->assertUnauthorized();

    dashExportUser(null);
    $this->get('/api/v1/admin/dashboard/export', ['Accept' => 'application/json'])->assertForbidden();
});

it('needs reports.export on top of dashboard.view (owner decision 2026-11-13)', function (Role $role): void {
    dashExportUser($role, export: false);
    $response = $this->get('/api/v1/admin/dashboard/export', ['Accept' => 'application/json']);

    in_array(Permission::ReportsExport, $role->permissions(), true) ? $response->assertOk() : $response->assertForbidden();
})->with(Role::cases());

it('refuses an invalid range, format, locale or unknown parameter with a 400', function (string $query): void {
    dashExportUser(Role::Manager);
    $this->get('/api/v1/admin/dashboard/export?'.$query, ['Accept' => 'application/json'])
        ->assertStatus(400)
        ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, 'bad-request'));
})->with([
    'unknown format' => ['format=pdf'],
    'unknown locale' => ['locale=fr'],
    'array format' => ['format[]=csv'],
    'unknown parameter' => ['format=csv&sort=-value'],
    'unlisted preset' => ['range=90d'],
    'dates with a preset' => ['range=7d&from=2026-11-01'],
    'custom without to' => ['range=custom&from=2026-11-01'],
    'impossible date' => ['range=custom&from=2026-02-30&to=2026-03-01'],
    'reversed range' => ['range=custom&from=2026-11-05&to=2026-11-01'],
]);
