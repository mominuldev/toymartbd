<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfilment\Tracking;

use App\Domain\Fulfilment\Actions\RefreshShipmentStatus;
use App\Domain\Fulfilment\Drivers\NullCourierGateway;
use App\Domain\Fulfilment\DTOs\CourierStatusResult;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\CourierRegistry;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Role;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Orders\Support;

/**
 * P6 status-ingestion helpers: a real order (admin-created, confirmed — stock reserved),
 * handed to the NullCourierGateway under the `steadfast` code, and booked through the real
 * ApplyCourierStatus path (a bulk-`submitted` shipment completed when the courier answers).
 * Needs GeographySeeder + DeliverySeeder.
 */
final class CourierTrackingKit
{
    public const string SECRET = 'null-secret';

    public static function gateway(string $code = 'steadfast'): NullCourierGateway
    {
        $gateway = new NullCourierGateway($code, webhookSecret: self::SECRET);
        app(CourierRegistry::class)->register($code, $gateway);

        return $gateway;
    }

    public static function confirmedOrder(): Order
    {
        Support::actingAs(Role::Manager);
        $variant = CartTestKit::variant('1000.00', 5);
        $total = test()->postJson('/api/v1/admin/orders/quote', Support::body([$variant->id => 2]))->assertOk()->json('grand_total');
        $id = (int) test()->postJson('/api/v1/admin/orders', Support::body([$variant->id => 2], ['expected_total' => $total]), ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->json('id');
        test()->postJson("/api/v1/admin/orders/{$id}/confirm")->assertSuccessful();

        $order = Order::query()->findOrFail($id);
        expect($order->status)->toBe(OrderStatus::Confirmed);

        return $order;
    }

    /** A shipment the courier holds: submitted, then booked by the courier's own answer. */
    public static function bookedShipment(NullCourierGateway $gateway, ?Order $order = null, string $consignment = 'CN-1001', string $code = 'steadfast'): Shipment
    {
        $order ??= self::confirmedOrder();
        $shipment = Shipment::query()->create([
            'order_id' => $order->id,
            'provider_id' => CourierProvider::query()->where('code', $code)->value('id'),
            'number' => 'SHP-26-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'status' => ShipmentStatus::Submitted,
            'pickup_requested_at' => now(),
            'weight_grams' => 900,
            'item_count' => 2,
            'cod_amount' => $order->due_total,
            'declared_value' => $order->grand_total,
        ]);

        $gateway->willReportStatus($consignment, self::result(ShipmentStatus::Booked, 'in_review', charge: '70.00'));
        expect(app(RefreshShipmentStatus::class)->handle($shipment->id, 'poll', $consignment))->toBe('applied');

        $shipment->refresh();
        expect($shipment->status)->toBe(ShipmentStatus::Booked)
            ->and($shipment->consignment_id)->toBe($consignment)
            ->and($order->refresh()->status)->toBe(OrderStatus::Shipped);

        return $shipment;
    }

    public static function result(ShipmentStatus $status, string $raw, ?CarbonImmutable $at = null, ?string $charge = null, ?string $collected = null): CourierStatusResult
    {
        return new CourierStatusResult($status, $raw, $at, $collected === null ? null : Money::of($collected), $charge === null ? null : Money::of($charge));
    }

    /** @param  array<string, mixed>  $body */
    public static function webhook(array $body, ?string $signature = self::SECRET, string $code = 'steadfast'): \Illuminate\Testing\TestResponse
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($signature !== null) {
            $headers['HTTP_X_NULL_SIGNATURE'] = $signature;
        }

        return test()->call('POST', "/api/v1/webhooks/couriers/{$code}", [], [], [], $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }
}
