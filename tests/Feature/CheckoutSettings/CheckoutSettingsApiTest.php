<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Support\RoleMatrix;

/*
 * GET /admin/settings/checkout — Store settings › Checkout & delivery rules (Figma B · 38),
 * docs/api/checkout-settings.md. The screen reads what checkout really does; these tests
 * also prove the "fixed" rows are true of the checkout endpoint itself.
 */

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
});

function checkoutSettingsOwner(): User
{
    return User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
}

it('lets settings.view read the checkout rules, nobody else (rule 21)', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/settings/checkout'));
})->with(RoleMatrix::dataset(Permission::SettingsView));

it('reports the checkout rules from the settings and rate card checkout uses', function (): void {
    $this->actingAs(checkoutSettingsOwner())->getJson('/api/v1/admin/settings/checkout')
        ->assertOk()
        ->assertExactJson([
            'guest_checkout' => true,
            'cash_on_delivery' => true,
            'online_methods' => [],
            'order_notes' => true,
            'order_note_max' => 500,
            'risk_advance' => ['available' => false, 'blocker' => 'no_bkash_gateway', 'bkash_status' => 'inactive', 'hold_score' => 70],
            'cod_advance' => ['zones' => ['outside_dhaka'], 'threshold' => '5000.00', 'amount' => '200.00'],
            'free_shipping_threshold' => '3000.00',
            'delivery_courier' => 'Steadfast',
            'delivery_charges' => [
                ['zone' => 'inside_dhaka_metro', 'charge' => '70.00'],
                ['zone' => 'dhaka_suburb', 'charge' => '100.00'],
                ['zone' => 'outside_dhaka', 'charge' => '130.00'],
            ],
        ]);
});

it('has one source of truth: the delivery endpoint and the default rate card change what it shows', function (): void {
    $this->actingAs(checkoutSettingsOwner());

    $this->putJson('/api/v1/admin/settings/delivery', ['free_shipping_threshold' => null])->assertOk();
    $steadfast = CourierProvider::query()->where('code', 'steadfast')->firstOrFail();
    $steadfast->forceFill(['base_charge_dhaka' => '65.00'])->save();

    $this->getJson('/api/v1/admin/settings/checkout')->assertOk()
        ->assertJsonPath('free_shipping_threshold', null)
        ->assertJsonPath('delivery_charges.0.charge', '65.00');

    $this->putJson('/api/v1/admin/settings/delivery', ['free_shipping_threshold' => '2500.50'])->assertOk();
    $this->getJson('/api/v1/admin/settings/checkout')->assertJsonPath('free_shipping_threshold', '2500.50');
});

it('says so when no courier is active rather than inventing a charge', function (): void {
    CourierProvider::query()->update(['is_active' => false]);

    $this->actingAs(checkoutSettingsOwner())->getJson('/api/v1/admin/settings/checkout')->assertOk()
        ->assertJsonPath('delivery_courier', null)
        ->assertJsonPath('delivery_charges.0.charge', null);
});

it('matches checkout: a guest COD order with a Bangla note is placed with no account', function (): void {
    $storefront = CartTestKit::storefrontToken();
    config(['risk.first_order_hold_threshold' => '100000.00']);
    $variant = CartTestKit::variant('1000.00', 5);
    $cart = CheckoutKit::cart([$variant->id => 1]);

    $this->withToken($storefront)->postJson('/api/v1/storefront/checkout', CheckoutKit::payload($cart, ['customer_note' => 'সন্ধ্যা ৬টার পরে দিন']), [
        CartTestKit::HEADER => $cart,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertCreated()->assertJsonPath('payment.method', 'cod');

    expect(Order::query()->sole()->customer_note)->toBe('সন্ধ্যা ৬টার পরে দিন');
});

it('matches checkout: no advance can be taken by bKash, so the method is refused', function (): void {
    $storefront = CartTestKit::storefrontToken();
    $variant = CartTestKit::variant('1000.00', 5);
    $cart = CheckoutKit::cart([$variant->id => 1]);

    $this->withToken($storefront)->postJson('/api/v1/storefront/checkout', CheckoutKit::payload($cart, ['payment_method' => 'bkash']), [
        CartTestKit::HEADER => $cart,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertUnprocessable();

    expect(Order::query()->count())->toBe(0);
});
