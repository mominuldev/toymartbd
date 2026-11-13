<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Drivers\Pathao\PathaoGateway;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\CourierWebhookReceipt;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\Http;

/*
 * P6 — Pathao's webhook contract through the real driver: every response (a 401 included)
 * echoes the global integration header, and the handshake and events are answered 202.
 * The signature is still checked first (rule 19).
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    Http::preventStrayRequests();
    CourierProvider::query()->where('code', 'pathao')->firstOrFail()->forceFill([
        'credentials' => ['client_id' => 'cid', 'client_secret' => 'csec', 'username' => 'u@example.com', 'password' => 'pw', 'store_id' => '1'],
        'webhook_secret' => 'pt-secret',
    ])->save();
});

function pathaoHook(array $body, ?string $signature): Illuminate\Testing\TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($signature !== null) {
        $server['HTTP_X_PATHAO_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/api/v1/webhooks/couriers/pathao', [], [], [], $server, json_encode($body, JSON_THROW_ON_ERROR));
}

it('answers the signed handshake 202 with the integration header, writing nothing', function (): void {
    pathaoHook(['event' => PathaoGateway::HANDSHAKE_EVENT], 'pt-secret')
        ->assertStatus(202)
        ->assertHeader(PathaoGateway::INTEGRATION_HEADER, PathaoGateway::INTEGRATION_SECRET)
        ->assertJson(['ignored' => true]);

    expect(CourierWebhookReceipt::query()->count())->toBe(0);
});

it('still refuses a bad signature with 401 — and still echoes the header', function (): void {
    pathaoHook(['event' => 'order.delivered', 'consignment_id' => 'DL1', 'timestamp' => '2026-11-13T05:00:00+00:00'], 'nope')
        ->assertUnauthorized()
        ->assertHeader(PathaoGateway::INTEGRATION_HEADER, PathaoGateway::INTEGRATION_SECRET);

    expect(CourierWebhookReceipt::query()->count())->toBe(0);
});

it('answers an order event 202 with the header and records it once', function (): void {
    $body = ['event' => 'order.delivered', 'consignment_id' => 'DL-UNKNOWN', 'timestamp' => '2026-11-13T05:00:00+00:00'];

    pathaoHook($body, 'pt-secret')->assertStatus(202)
        ->assertHeader(PathaoGateway::INTEGRATION_HEADER, PathaoGateway::INTEGRATION_SECRET)
        ->assertJson(['replayed' => false]);
    pathaoHook($body, 'pt-secret')->assertStatus(202)->assertJson(['replayed' => true]);

    // No shipment carries that consignment: recorded, nothing re-queried, no HTTP call.
    expect(CourierWebhookReceipt::query()->sole()->result)->toBe('unknown_shipment');
});
