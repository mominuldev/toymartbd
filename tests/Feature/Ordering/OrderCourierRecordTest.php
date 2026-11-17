<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Support\RoleMatrix;

/*
 * The order detail's external-evidence panel (regression 2026-11-17: it was a hard-coded "not
 * connected" card that never asked any courier). GET /orders/{order}/courier-record runs the same
 * CourierHistoryLookup as Fraud & risk › Phone lookup on the order's phone — verified external
 * data, apart from our score (rule 24), never a zero for a courier that cannot answer (rule 33b).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    (new DeliverySeeder)->run();
});

it('guards GET /orders/{order}/courier-record with orders.view', function (): void {
    $order = Order::factory()->create();

    RoleMatrix::assert($this, Permission::OrdersView, fn () => $this->getJson("/api/v1/admin/orders/{$order->id}/courier-record"));
});

it('asks Steadfast about the order’s phone once its keys are saved, and never returns the phone', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh(), 'web');
    CourierProvider::query()->where('code', 'steadfast')->firstOrFail()
        ->forceFill(['credentials' => ['api_key' => 'k', 'secret_key' => 's']])->save();
    $order = Order::factory()->create(['shipping_phone' => '+8801712345678']);
    Http::fake(['https://portal.packzy.com/api/v1/fraud_check/score/01712345678' => Http::response([
        'status' => 200, 'total_reports' => 2, 'delivery_ratio' => 80, 'cancellation_ratio' => 20, 'volume_range' => '10+',
    ])]);

    $response = $this->getJson("/api/v1/admin/orders/{$order->id}/courier-record")->assertOk();

    $steadfast = collect($response->json('providers'))->firstWhere('provider', 'steadfast');
    expect($steadfast['connected'])->toBeTrue()
        ->and($steadfast['delivery_ratio'])->toBe('0.8000')
        ->and($steadfast['volume'])->toBe('10+');
    expect($response->getContent())->not->toContain('1712345678');
});

it('says "not connected" with a reason when no courier can answer, sending nothing', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh(), 'web');
    $order = Order::factory()->create(['shipping_phone' => '+8801712345678']);

    $providers = collect($this->getJson("/api/v1/admin/orders/{$order->id}/courier-record")->assertOk()->json('providers'))->keyBy('provider');

    expect($providers['steadfast']['connected'])->toBeFalse()
        ->and($providers['steadfast']['reason'])->toBe('no_merchant_account')
        ->and($providers['steadfast']['total_parcels'])->toBeNull();
    Http::assertNothingSent();
});
