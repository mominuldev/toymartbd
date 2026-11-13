<?php

declare(strict_types=1);

use App\Domain\Marketing\Models\Coupon;
use App\Domain\Ordering\Actions\PlaceOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Support\RoleMatrix;

/*
 * Coupon builder (P7): bulk unique codes in a batch, the usage report (masked phones,
 * delivered revenue only), Dhaka-time schedule, and the role matrix.
 */

function builderBody(array $overrides = []): array
{
    return [
        'description' => 'নববর্ষের কুপন',
        'type' => 'fixed',
        'value' => '100',
        'min_order_amount' => '500',
        'usage_limit_total' => 1,
        'usage_limit_per_customer' => 1,
        'applies_to' => 'all',
        'count' => 25,
        'prefix' => 'nb',
        'batch' => 'boishakh-26',
        ...$overrides,
    ];
}

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
});

it('generates unique single-use codes in one batch', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');

    $r = $this->postJson('/api/v1/admin/coupons/generate', builderBody())->assertCreated();

    $codes = $r->json('codes');
    expect($r->json('count'))->toBe(25)->and($r->json('batch'))->toBe('BOISHAKH-26')
        ->and(count(array_unique($codes)))->toBe(25)
        ->and($codes[0])->toMatch('/^NB-[2-9A-HJKMNP-Z]{8}$/')
        ->and(Coupon::query()->where('batch', 'BOISHAKH-26')->count())->toBe(25)
        ->and(Coupon::query()->first()->usage_limit_total)->toBe(1);

    $this->getJson('/api/v1/admin/coupons?filter[batch]=boishakh-26')->assertOk()->assertJsonCount(25, 'data');
    // A batch label is used once.
    $this->postJson('/api/v1/admin/coupons/generate', builderBody(['count' => 1]))->assertUnprocessable()->assertJsonPath('errors.0.field', 'batch');
    $this->postJson('/api/v1/admin/coupons/generate', builderBody(['count' => 1001, 'batch' => 'X2']))->assertUnprocessable();
});

it('reads a coupon window without an offset as Dhaka time', function (): void {
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh(), 'web');

    $this->postJson('/api/v1/admin/coupons', [
        'code' => 'DHAKA', 'type' => 'fixed', 'value' => '50', 'applies_to' => 'all',
        'starts_at' => '2026-12-16T00:00', 'ends_at' => '2026-12-17T00:00',
    ])->assertCreated()->assertJsonPath('starts_at', '2026-12-15T18:00:00Z');
});

it('reports a coupon and its batch: masked phones, delivered revenue only', function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    config(['risk.first_order_hold_threshold' => '100000.00']);
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh(), 'web');
    $coupon = Coupon::factory()->fixed('100.00')->create(['code' => 'REP100', 'batch' => 'B1']);
    $v = CartTestKit::variant('1000.00', 10);

    $delivered = app(PlaceOrder::class)->handle(CheckoutKit::data(CheckoutKit::cart([$v->id => 1], 'REP100')));
    // Fixture shortcut (statuses normally move only through OrderTransitions).
    Illuminate\Support\Facades\DB::table('orders')->where('id', $delivered->id)->update(['status' => OrderStatus::Delivered->value]);
    app(PlaceOrder::class)->handle(CheckoutKit::data(CheckoutKit::cart([$v->id => 2], 'REP100'), '01812345678'));

    $r = $this->getJson("/api/v1/admin/coupons/{$coupon->id}/report")->assertOk();

    expect($r->json('summary'))->toBe(['orders' => 2, 'discount_total' => '200.00', 'gross_sales' => '3060.00', 'delivered_orders' => 1, 'delivered_revenue' => '1030.00'])
        ->and($r->json('batch.orders'))->toBe(2)
        ->and($r->json('recent.0.phone'))->not->toContain('12345678')
        ->and(json_encode($r->json()))->not->toContain('+8801812345678');
});

it('guards POST /coupons/generate with marketing.manage', function (): void {
    $n = 0;
    RoleMatrix::assert($this, Permission::MarketingManage, function () use (&$n) {
        $n++;

        return $this->postJson('/api/v1/admin/coupons/generate', builderBody(['count' => 2, 'batch' => "ROLE{$n}"]));
    }, 201);
});

it('guards GET /coupons/{coupon}/report with marketing.view', function (): void {
    $coupon = Coupon::factory()->create();
    RoleMatrix::assert($this, Permission::MarketingView, fn () => $this->getJson("/api/v1/admin/coupons/{$coupon->id}/report"));
});
