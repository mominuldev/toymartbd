<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Drivers\Steadfast\SteadfastGateway;
use App\Domain\Fulfilment\Models\CourierFraudLookup;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Services\CourierHistoryLookup;
use App\Support\PhoneNumber;
use Database\Seeders\DeliverySeeder;
use Illuminate\Support\Facades\Http;

/*
 * Steadfast webhook verification/parsing and the courier-history (fraud lookup) panel
 * source, against hand-authored, unverified fixtures. Rules 19, 24, 24a, 33a, 33b.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->seed(DeliverySeeder::class);
    CourierProvider::query()->where('code', 'steadfast')->firstOrFail()
        ->forceFill(['webhook_secret' => 'sf-webhook-token'])->save();
});

function steadfastWebhookBody(string $name): string
{
    $fixture = json_decode((string) file_get_contents(base_path("tests/Fixtures/Steadfast/{$name}.json")), true, 64, JSON_THROW_ON_ERROR);

    return (string) json_encode($fixture['body']);
}

it('accepts a webhook with the configured Bearer token, with or without the HMAC header', function (): void {
    $body = steadfastWebhookBody('webhook.delivery_status');
    $driver = app(SteadfastGateway::class);

    expect($driver->verifyWebhook(['Authorization' => 'Bearer sf-webhook-token'], $body))->toBeTrue()
        ->and($driver->verifyWebhook(['authorization' => ['Bearer sf-webhook-token'], 'x-signature' => hash_hmac('sha256', $body, 'sf-webhook-token')], $body))->toBeTrue();
});

it('rejects a webhook with a wrong token, no token, a bad signature, or no secret configured', function (): void {
    $body = steadfastWebhookBody('webhook.delivery_status');
    $driver = app(SteadfastGateway::class);

    expect($driver->verifyWebhook(['Authorization' => 'Bearer wrong'], $body))->toBeFalse()
        ->and($driver->verifyWebhook([], $body))->toBeFalse()
        ->and($driver->verifyWebhook(['Authorization' => 'sf-webhook-token'], $body))->toBeFalse()
        ->and($driver->verifyWebhook(['Authorization' => 'Bearer sf-webhook-token', 'X-Signature' => hash_hmac('sha256', $body.' ', 'sf-webhook-token')], $body))->toBeFalse();

    CourierProvider::query()->where('code', 'steadfast')->firstOrFail()->forceFill(['webhook_secret' => null])->save();
    expect(app(SteadfastGateway::class)->verifyWebhook(['Authorization' => 'Bearer '], $body))->toBeFalse();
});

it('turns a delivery_status notification into a re-query trigger with a string consignment id', function (): void {
    $event = app(SteadfastGateway::class)->parseWebhook(steadfastWebhookBody('webhook.delivery_status'));

    expect($event)->not->toBeNull()
        ->and($event?->consignmentId)->toBe('12345')
        ->and($event?->merchantOrderRef)->toBe('INV-67890')
        ->and($event?->rawStatus)->toBe('delivered')
        ->and($event?->eventId)->toStartWith('steadfast:');

    // Same notification replayed → same event id (idempotent ingestion).
    expect(app(SteadfastGateway::class)->parseWebhook(steadfastWebhookBody('webhook.delivery_status'))?->eventId)->toBe($event?->eventId);
});

it('ignores notifications that carry no shipment status, and malformed bodies', function (string $body): void {
    expect(app(SteadfastGateway::class)->parseWebhook($body))->toBeNull();
})->with([
    'tracking_update' => fn (): string => steadfastWebhookBody('webhook.tracking_update'),
    'not json' => '<html>',
    'no consignment' => '{"notification_type":"delivery_status","status":"delivered"}',
    'payment_request' => '{"notification_type":"payment_request","consignment_id":1}',
]);

it('reports every provider as not connected today, never as zero (rule 33b)', function (): void {
    $panel = app(CourierHistoryLookup::class)->forPhone('01712345678');

    expect($panel)->toHaveCount(2);
    $byCode = collect($panel)->keyBy('provider');

    expect($byCode['steadfast']['connected'])->toBeFalse()
        ->and($byCode['steadfast']['reason'])->toBe('no_merchant_account')
        ->and($byCode['steadfast']['total_delivered'])->toBeNull()
        ->and($byCode['steadfast']['delivery_ratio'])->toBeNull()
        ->and($byCode['pathao']['connected'])->toBeFalse()
        ->and($byCode['pathao']['reason'])->toBeIn(['no_official_api', 'driver_missing'])
        ->and($byCode['pathao']['total_parcels'])->toBeNull();

    expect(CourierFraudLookup::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('stays not connected with credentials while the provider is unverified', function (): void {
    CourierProvider::query()->where('code', 'steadfast')->firstOrFail()
        ->forceFill(['credentials' => ['api_key' => 'k', 'secret_key' => 's']])->save();

    $steadfast = collect(app(CourierHistoryLookup::class)->forPhone('01712345678'))->firstWhere('provider', 'steadfast');

    expect($steadfast['connected'])->toBeFalse()->and($steadfast['reason'])->toBe('provider_unverified');
    Http::assertNothingSent();
});

it('once connected and verified, reads the official fraud_check, stores it timestamped and reuses it', function (): void {
    CourierProvider::query()->where('code', 'steadfast')->firstOrFail()
        ->forceFill(['credentials' => ['api_key' => 'k', 'secret_key' => 's'], 'verified' => true])->save();
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/Steadfast/fraud_check.json')), true);
    Http::fake(['https://portal.packzy.com/api/v1/fraud_check/01712345678' => Http::response($fixture['body'])]);

    $first = collect(app(CourierHistoryLookup::class)->forPhone(PhoneNumber::parse('+8801712345678')))->firstWhere('provider', 'steadfast');
    $again = collect(app(CourierHistoryLookup::class)->forPhone('01712345678'))->firstWhere('provider', 'steadfast');

    expect($first['connected'])->toBeTrue()
        ->and($first['total_parcels'])->toBe(24)
        ->and($first['total_delivered'])->toBe(9)
        ->and($first['total_cancelled'])->toBe(15)
        ->and($first['total_fraud_reports'])->toBe(0)
        ->and($first['delivery_ratio'])->toBe('0.3750')
        ->and($first['cancellation_ratio'])->toBe('0.6250')
        ->and($first['fetched_at'])->not->toBeNull()
        ->and($again['total_parcels'])->toBe(24);

    Http::assertSentCount(1);
    $row = CourierFraudLookup::query()->sole();
    expect($row->phone)->toBe('+8801712345678')->and($row->source)->toBe('official_api');
});
