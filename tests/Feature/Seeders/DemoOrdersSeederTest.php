<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Address;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\WishlistItem;
use App\Domain\Finance\Models\CourierSettlement;
use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Notification\Models\CartRecovery;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderReturn;
use App\Domain\Payment\Models\Refund;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Domain\Risk\Models\RiskVerificationCall;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BrandDemoSeeder;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\CategoryDemoSeeder;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoCustomersSeeder;
use Database\Seeders\DemoInventorySeeder;
use Database\Seeders\DemoOrdersSeeder;
use Database\Seeders\ExpenseCategoriesSeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * The demo order history (DemoCustomersSeeder + DemoOrdersSeeder): every order status the
 * admin shows has rows, the money side is real (revenue on delivery, cash from courier
 * settlements and manual remittances, a paid refund), the risk queue has work, nothing is
 * sent anywhere, both build invariants hold, and a second run adds nothing.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    config([
        'demo.transactions' => true,
        'demo.history_days' => 60,
        'services.storefront.url' => '',
        'services.storefront.revalidate_secret' => '',
    ]);

    $this->seed([
        RolesAndPermissionsSeeder::class, GeographySeeder::class, CatalogReferenceSeeder::class,
        DeliverySeeder::class, ExpenseCategoriesSeeder::class,
    ]);
    User::query()->firstOrCreate(['email' => 'owner@toymart.test'], User::factory()->raw(['name' => 'Local Owner', 'email' => 'owner@toymart.test']))
        ->assignRole(Role::SuperAdmin->value);
    $this->seed([CategoryDemoSeeder::class, BrandDemoSeeder::class, DemoCatalogSeeder::class, DemoInventorySeeder::class]);
});

/** @return array<string, int> */
function demoOrderCounts(): array
{
    return [
        'customers' => Customer::query()->count(),
        'addresses' => Address::query()->count(),
        'wishlist' => WishlistItem::query()->count(),
        'orders' => Order::query()->count(),
        'shipments' => Shipment::query()->count(),
        'returns' => OrderReturn::query()->count(),
        'settlements' => CourierSettlement::query()->count(),
        'ledger' => FinancialTransaction::query()->count(),
        'stock_movements' => DB::table('stock_movements')->count(),
        'carts' => DB::table('carts')->count(),
    ];
}

it('seeds a whole, consistent order history through the Actions — once', function (): void {
    $this->seed([DemoCustomersSeeder::class, DemoOrdersSeeder::class]);

    // The clock is never left frozen.
    expect(CarbonImmutable::hasTestNow())->toBeFalse();

    // Accounts: 30 sign-ups, each with a landmark on every address and a default one.
    $demo = Customer::query()->whereIn('phone', array_map(static fn (string $p): string => App\Support\PhoneNumber::parse($p)->e164(), DemoCustomersSeeder::PHONES));
    expect((clone $demo)->count())->toBe(30)
        ->and((clone $demo)->whereNotNull('phone_verified_at')->count())->toBe(30)
        ->and(Address::query()->whereNull('landmark')->orWhere('landmark', '')->count())->toBe(0)
        ->and(Address::query()->where('is_default_shipping', true)->distinct()->count('customer_id'))->toBe(Customer::query()->has('addresses')->count())
        ->and(WishlistItem::query()->count())->toBeGreaterThan(10);

    // ~60 days of orders: 120–150, every status the admin filters by.
    $byStatus = Order::query()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(static fn ($n): int => (int) $n)->all();
    foreach (OrderStatus::cases() as $status) {
        expect($byStatus[$status->value] ?? 0)->toBeGreaterThanOrEqual(1, "no {$status->value} order");
    }
    expect(array_sum($byStatus))->toBeGreaterThanOrEqual(120)->toBeLessThanOrEqual(150)
        ->and($byStatus['delivered'])->toBeGreaterThan(max($byStatus['pending'], $byStatus['cancelled']))
        ->and(Order::query()->where('source', 'web')->count())->toBeGreaterThan(0)
        ->and(Order::query()->whereIn('source', ['phone', 'facebook', 'whatsapp'])->whereNotNull('created_by')->count())->toBeGreaterThan(0)
        ->and(DB::table('order_events')->where('type', 'edited')->count())->toBeGreaterThanOrEqual(2)
        ->and(DB::table('order_notes')->count())->toBeGreaterThan(0);

    // Revenue recognised on delivery for every delivered order (rule 10), and cash besides.
    $delivered = Order::query()->whereIn('status', ['delivered', 'completed'])->pluck('id');
    $recognised = FinancialTransaction::query()->where('source_type', 'order')->where('type', 'sale_revenue')
        ->where('is_recognised_revenue', true)->whereIn('source_id', $delivered)->distinct()->count('source_id');
    expect($recognised)->toBe($delivered->count())
        ->and(FinancialTransaction::query()->where('type', 'cod_received')->where('source_type', 'courier_settlement_item')->count())->toBeGreaterThan(0)
        ->and(FinancialTransaction::query()->where('type', 'cod_received')->where('source_type', 'manual_cod_receipt')->count())->toBeGreaterThan(0)
        ->and(FinancialTransaction::query()->where('type', 'cod_received')->where('is_recognised_revenue', true)->count())->toBe(0);

    // Courier: booked through the integration, delivered and returned; remittances confirmed, the newest still open.
    expect(Shipment::query()->where('status', 'delivered')->count())->toBeGreaterThan(0)
        ->and(Shipment::query()->where('status', 'returned')->count())->toBeGreaterThan(0)
        ->and(Shipment::query()->whereNotIn('status', ['delivered', 'returned'])->count())->toBe(0)
        ->and(CourierSettlement::query()->where('status', 'reconciled')->count())->toBeGreaterThanOrEqual(1)
        ->and(CourierSettlement::query()->where('status', 'received')->count())->toBe(1)
        ->and(DB::table('courier_settlement_items')->where('variance_type', 'short')->where('review_status', 'accepted')->count())->toBe(1);

    // Returns: a paid refund, a rejected refund, a rejected return, and returns waiting to come
    // back / for inspection (how many depends on the time of day the seed runs).
    expect(Refund::query()->where('status', 'completed')->whereNotNull('processed_at')->count())->toBeGreaterThanOrEqual(1)
        ->and(Refund::query()->where('status', 'rejected')->count())->toBe(1)
        ->and(OrderReturn::query()->where('status', 'rejected')->count())->toBe(1)
        ->and(OrderReturn::query()->where('status', 'requested')->count())->toBeGreaterThanOrEqual(1)
        ->and(OrderReturn::query()->where('status', 'received')->count())->toBeGreaterThanOrEqual(1);

    // Risk: held orders still waiting in the queue, others verified or rejected after calls.
    expect(Order::query()->where('status', 'on_hold')->where('verification_status', 'pending')->count())->toBeGreaterThanOrEqual(3)
        ->and(Order::query()->where('verification_status', 'verified')->count())->toBeGreaterThanOrEqual(2)
        ->and(Order::query()->where('verification_status', 'failed')->count())->toBeGreaterThanOrEqual(2)
        ->and(RiskVerificationCall::query()->count())->toBeGreaterThan(0)
        ->and(CustomerRiskProfile::query()->count())->toBe(Order::query()->distinct()->count('shipping_phone'));

    // Carts: abandoned ones detected (one skipped for no consent), one recovered, some still open.
    expect(CartRecovery::query()->where('status', 'messaged')->count())->toBeGreaterThanOrEqual(1)
        ->and(CartRecovery::query()->where('status', 'skipped')->where('skip_reason', 'no_consent')->count())->toBe(1)
        ->and(DB::table('carts')->whereNull('converted_at')->where('item_count', '>', 0)->count())->toBeGreaterThanOrEqual(2);

    // Customer list totals, and no message ever left (sends are recorded, never run).
    expect(Customer::query()->where('orders_count', '>', 0)->where('total_spent', '>', 0)->count())->toBeGreaterThan(20)
        ->and(NotificationLog::query()->whereIn('status', ['sent', 'delivered', 'failed'])->count())->toBe(0);

    // Both build invariants.
    expect(Artisan::call('toymart:inventory:reconcile'))->toBe(0)
        ->and(Artisan::call('toymart:finance:verify'))->toBe(0);

    // Idempotent: a second run adds nothing.
    $before = demoOrderCounts();
    $this->seed([DemoCustomersSeeder::class, DemoOrdersSeeder::class]);
    expect(demoOrderCounts())->toBe($before);
});
