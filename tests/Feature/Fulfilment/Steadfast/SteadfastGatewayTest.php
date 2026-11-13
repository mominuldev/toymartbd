<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Drivers\Steadfast\SteadfastGateway;
use App\Domain\Fulfilment\Drivers\Steadfast\SteadfastStatusMap;
use App\Domain\Fulfilment\Drivers\Steadfast\SteadfastUnavailable;
use App\Domain\Fulfilment\DTOs\ShipmentRequest;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Services\CourierRegistry;
use App\Support\Money;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Steadfast driver against HAND-AUTHORED, UNVERIFIED fixtures (tests/Fixtures/Steadfast/,
 * each headed with its source). No merchant account and no sandbox exist (rule 33a), so
 * no test here — or anywhere — reaches the real API: stray requests are refused.
 * Geography ids: Dhaka 47 (Savar 365), Chattogram 8.
 */

const SF_BASE = 'https://portal.packzy.com/api/v1';

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $provider = CourierProvider::query()->where('code', 'steadfast')->firstOrFail();
    $provider->forceFill([
        'credentials' => ['api_key' => 'test-api-key', 'secret_key' => 'test-secret-key'],
        'webhook_secret' => 'sf-webhook-token',
    ])->save();
});

/**
 * A fixture's HTTP response. `{{name}}` placeholders in the body are replaced first.
 *
 * @param  array<string, string>  $vars
 * @return array{status: int, body: string}
 */
function steadfastFixture(string $name, array $vars = []): array
{
    $fixture = json_decode((string) file_get_contents(base_path("tests/Fixtures/Steadfast/{$name}.json")), true, 64, JSON_THROW_ON_ERROR);
    $body = array_key_exists('body_raw', $fixture) ? $fixture['body_raw'] : json_encode($fixture['body'], JSON_UNESCAPED_UNICODE);
    foreach ($vars as $key => $value) {
        $body = str_replace('{{'.$key.'}}', $value, $body);
    }

    return ['status' => $fixture['http_status'], 'body' => $body];
}

/** @param  array<string, string>  $vars */
function steadfastResponse(string $name, array $vars = []): GuzzleHttp\Promise\PromiseInterface
{
    $fixture = steadfastFixture($name, $vars);

    return Http::response($fixture['body'], $fixture['status'], ['Content-Type' => 'application/json']);
}

/** @param  array<string, mixed>  $overrides */
function steadfastRequest(array $overrides = []): ShipmentRequest
{
    $values = array_merge([
        'shipmentId' => 1,
        'merchantOrderRef' => 'SHP-26-000001',
        'recipientName' => 'রহিম উদ্দিন',
        'recipientPhone' => '+8801712345678',
        'addressLine' => 'House 12, Road 5, Dhanmondi, Dhaka',
        'landmark' => 'Opposite Dhanmondi Lake gate',
        'districtId' => 47,
        'upazilaId' => null,
        'courierLocation' => null,
        'codAmount' => Money::of('1060.00'),
        'declaredValue' => Money::of('990.00'),
        'weightGrams' => 800,
        'itemCount' => 2,
        'itemDescription' => 'Lego Classic 11001 x1; Puzzle x1',
        'note' => 'Call before delivery',
    ], $overrides);

    return new ShipmentRequest(...$values);
}

function steadfast(): SteadfastGateway
{
    return app(SteadfastGateway::class);
}

it('is registered under its provider code and needs no location mapping', function (): void {
    $driver = app(CourierRegistry::class)->for('steadfast');

    expect($driver)->toBeInstanceOf(SteadfastGateway::class)
        ->and($driver->code())->toBe('steadfast')
        ->and($driver->requiresLocationMapping())->toBeFalse()
        ->and($driver->supportsBulk())->toBeTrue();
});

it('books through create_order and stores ids as strings, sending the documented body', function (): void {
    Http::fake([SF_BASE.'/create_order' => steadfastResponse('create_order.success', ['invoice' => 'SHP-26-000001', 'tracking_code' => '7DB724A65D4'])]);

    $result = steadfast()->book(steadfastRequest());

    expect($result->accepted)->toBeTrue()
        ->and($result->consignmentId)->toBe('131822154')
        ->and($result->trackingCode)->toBe('7DB724A65D4')
        ->and($result->providerStatusRaw)->toBe('in_review')
        ->and($result->charge)->toBeNull()
        ->and($result->response['consignment']['consignment_id'])->toBe(131822154);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->method() === 'POST'
            && $request->url() === SF_BASE.'/create_order'
            && $request->header('Api-Key') === ['test-api-key']
            && $request->header('Secret-Key') === ['test-secret-key']
            && $body['invoice'] === 'SHP-26-000001'
            && $body['recipient_name'] === 'রহিম উদ্দিন'
            && $body['recipient_phone'] === '01712345678'
            && $body['recipient_address'] === 'House 12, Road 5, Dhanmondi, Dhaka (Landmark: Opposite Dhanmondi Lake gate)'
            && $body['cod_amount'] === '1060.00'
            && $body['note'] === 'Call before delivery'
            && $body['total_lot'] === 2
            && $body['delivery_type'] === 0
            && $body['item_description'] === 'Lego Classic 11001 x1 Puzzle x1';
    });
});

it('keeps a tracking code of any length from 8 to 15 hex characters verbatim', function (string $code): void {
    Http::fake([SF_BASE.'/create_order' => steadfastResponse('create_order.success', ['invoice' => 'SHP-26-000001', 'tracking_code' => $code])]);

    $result = steadfast()->book(steadfastRequest());

    expect($result->trackingCode)->toBe($code)->and($result->consignmentId)->toBeString();
})->with(['15BAEB8A', '5491DE4A4', '7DB724A65D4', 'E942C9CBFFFFBEF']);

it('moves the landmark into the note rather than overflowing the 250-character address', function (): void {
    Http::fake([SF_BASE.'/create_order' => steadfastResponse('create_order.success', ['invoice' => 'SHP-26-000001', 'tracking_code' => '15BAEB8A'])]);

    steadfast()->book(steadfastRequest(['addressLine' => str_repeat('ক', 240)]));

    Http::assertSent(fn (Request $r): bool => $r->data()['recipient_address'] === str_repeat('ক', 240)
        && $r->data()['note'] === 'Landmark: Opposite Dhanmondi Lake gate | Call before delivery');
});

it('returns a refusal for a validation failure, with the provider message', function (): void {
    Http::fake([SF_BASE.'/create_order' => steadfastResponse('create_order.validation-error')]);

    $result = steadfast()->book(steadfastRequest());

    expect($result->accepted)->toBeFalse()
        ->and($result->consignmentId)->toBeNull()
        ->and($result->error)->toContain('HTTP 422')->toContain('invoice has already been taken');
});

it('refuses on a 401 without throwing, so the job never retries into a key lockout', function (): void {
    Http::fake([SF_BASE.'/create_order' => steadfastResponse('unauthorized')]);

    $result = steadfast()->book(steadfastRequest());

    expect($result->accepted)->toBeFalse()->and($result->error)->toContain('credentials rejected');
    Http::assertSentCount(1);
});

it('refuses locally, without calling Steadfast, for an invalid invoice or phone', function (array $overrides, string $message): void {
    Http::fake();

    $result = steadfast()->book(steadfastRequest($overrides));

    expect($result->accepted)->toBeFalse()->and($result->error)->toContain($message);
    Http::assertNothingSent();
})->with([
    'invoice with a slash' => [['merchantOrderRef' => 'SHP/26/1'], 'invoice'],
    'defunct 011 prefix' => [['recipientPhone' => '01112345678'], 'phone'],
    'address over 250' => [['addressLine' => str_repeat('a', 251)], 'longer than 250'],
]);

it('throws on a timeout so the booking job retries', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    steadfast()->book(steadfastRequest());
})->throws(SteadfastUnavailable::class, 'could not reach');

it('throws on a 5xx', function (): void {
    Http::fake([SF_BASE.'/create_order' => steadfastResponse('server-error')]);

    steadfast()->book(steadfastRequest());
})->throws(SteadfastUnavailable::class, 'HTTP 500');

it('throws on a malformed 200 rather than guessing whether the order exists', function (): void {
    Http::fake([SF_BASE.'/create_order' => steadfastResponse('create_order.malformed')]);

    steadfast()->book(steadfastRequest());
})->throws(SteadfastUnavailable::class, 'unexpected response body');

it('refuses to book with no credentials stored, and sends nothing', function (): void {
    CourierProvider::query()->where('code', 'steadfast')->firstOrFail()->forceFill(['credentials' => null])->save();
    Http::fake();

    expect(steadfast()->book(steadfastRequest())->error)->toContain('not configured')
        ->and(steadfast()->bookMany([steadfastRequest()])->submitted)->toBeFalse();
    Http::assertNothingSent();
});

it('refuses a production booking while the provider is unverified (rule 33a)', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    Http::fake();

    expect(CourierProvider::query()->where('code', 'steadfast')->value('verified'))->toBeFalse();

    $single = steadfast()->book(steadfastRequest());
    $bulk = steadfast()->bookMany([steadfastRequest()]);

    expect($single->accepted)->toBeFalse()->and($single->error)->toContain('unverified')
        ->and($bulk->submitted)->toBeFalse()->and($bulk->error)->toContain('unverified');
    Http::assertNothingSent();
});

it('submits bulk orders as a JSON-encoded string and reports only acknowledged refs, never ids (rule 24d)', function (): void {
    Http::fake([SF_BASE.'/create_order/bulk-order' => steadfastResponse('bulk-order.response', ['invoice_1' => 'SHP-26-000001', 'invoice_2' => 'SHP-26-000002'])]);

    $result = steadfast()->bookMany([
        steadfastRequest(),
        steadfastRequest(['shipmentId' => 2, 'merchantOrderRef' => 'SHP-26-000002']),
    ]);

    expect($result->submitted)->toBeTrue()
        ->and($result->refs)->toBe(['SHP-26-000001'])
        ->and($result->error)->toContain('SHP-26-000002: THIS_INVOICE_ALREADY_EXISTS');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data()['data'] ?? null;

        return is_string($data) && count(json_decode($data, true)) === 2
            && json_decode($data, true)[0]['invoice'] === 'SHP-26-000001';
    });
});

it('chunks bulk submissions at 500 orders per request', function (): void {
    $sizes = [];
    Http::fake(function (Request $request) use (&$sizes) {
        $entries = json_decode($request->data()['data'], true);
        $sizes[] = count($entries);

        return Http::response(['status' => 200, 'message' => 'We have a response for you.', 'data' => array_map(
            fn (array $e): array => ['invoice' => $e['invoice'], 'status' => 'success', 'consignment_id' => null, 'tracking_code' => null, 'error' => null],
            $entries,
        )]);
    });

    $requests = array_map(fn (int $i): ShipmentRequest => steadfastRequest(['shipmentId' => $i, 'merchantOrderRef' => sprintf('SHP-26-%06d', $i)]), range(1, 1201));
    $result = steadfast()->bookMany($requests);

    expect($sizes)->toBe([500, 500, 201])
        ->and($result->submitted)->toBeTrue()
        ->and($result->refs)->toHaveCount(1201)
        ->and($result->error)->toBeNull();
});

it('throws when the first bulk chunk cannot be sent, but keeps earlier chunks when a later one fails', function (): void {
    $calls = 0;
    Http::fake(function (Request $request) use (&$calls) {
        $calls++;
        if ($calls === 2) {
            throw new ConnectionException('timeout');
        }

        return Http::response(['status' => 200, 'data' => array_map(
            fn (array $e): array => ['invoice' => $e['invoice'], 'status' => 'success', 'error' => null],
            json_decode($request->data()['data'], true),
        )]);
    });

    $requests = array_map(fn (int $i): ShipmentRequest => steadfastRequest(['shipmentId' => $i, 'merchantOrderRef' => "SHP-26-{$i}"]), range(1, 600));
    $result = steadfast()->bookMany($requests);

    expect($result->submitted)->toBeTrue()
        ->and($result->refs)->toHaveCount(500)
        ->and($result->error)->toContain('chunk 2');

    Http::fake(fn () => throw new ConnectionException('timeout'));
    expect(fn () => steadfast()->bookMany([steadfastRequest()]))->toThrow(SteadfastUnavailable::class);
});

it('maps every documented Steadfast delivery status', function (string $raw, ShipmentStatus $expected): void {
    Http::fake([SF_BASE.'/status_by_cid/131822154' => steadfastResponse('status_by_cid', ['delivery_status' => $raw])]);

    $result = steadfast()->status('131822154');

    expect($result->status)->toBe($expected)
        ->and($result->rawStatus)->toBe($raw)
        ->and($result->consignmentId)->toBe('131822154');
})->with([
    ['pending', ShipmentStatus::InTransit],
    ['delivered_approval_pending', ShipmentStatus::OutForDelivery],
    ['partial_delivered_approval_pending', ShipmentStatus::OutForDelivery],
    ['cancelled_approval_pending', ShipmentStatus::OnHold],
    ['unknown_approval_pending', ShipmentStatus::OnHold],
    ['delivered', ShipmentStatus::Delivered],
    ['partial_delivered', ShipmentStatus::PartiallyDelivered],
    ['cancelled', ShipmentStatus::Returned],
    ['hold', ShipmentStatus::OnHold],
    ['in_review', ShipmentStatus::Booked],
    ['unknown', ShipmentStatus::OnHold],
    ['in review', ShipmentStatus::Booked],
]);

it('covers the guide\'s full status list, the return-progress values and nothing invented', function (): void {
    foreach (SteadfastStatusMap::DOCUMENTED as $raw) {
        expect(SteadfastGateway::statusMap())->toHaveKey($raw);
    }

    expect(SteadfastStatusMap::DOCUMENTED)->toHaveCount(11)
        ->and(SteadfastGateway::statusMap())->toHaveCount(18)
        ->and(SteadfastGateway::statusMap())->not->toHaveKey('in_transit')
        ->and(SteadfastStatusMap::toShipmentStatus('something_new'))->toBe(ShipmentStatus::OnHold);
});

it('throws on a malformed status answer', function (): void {
    Http::fake([SF_BASE.'/status_by_cid/1' => Http::response(['status' => 200], 200)]);

    steadfast()->status('1');
})->throws(SteadfastUnavailable::class);

it('reads the balance, which can be negative', function (): void {
    Http::fake([SF_BASE.'/get_balance' => steadfastResponse('get_balance')]);

    expect(steadfast()->balance()->toDecimal())->toBe('-10.00');
});

it('quotes from the stored rate card, stepping at 1 kg (rule 32a)', function (int $grams, ?int $upazila, string $charge): void {
    $quote = steadfast()->quote(steadfastRequest(['weightGrams' => $grams, 'upazilaId' => $upazila, 'codAmount' => Money::of('1070.00')]));

    expect($quote->charge->toDecimal())->toBe($charge);
})->with([
    'Dhaka 1 kg' => [1000, null, '70.00'],
    'Dhaka 1.001 kg (Pathao would still be base)' => [1001, null, '90.00'],
    'Dhaka 2 kg' => [2000, null, '90.00'],
    'Dhaka 2.5 kg' => [2500, null, '110.00'],
    'Savar suburb 0.5 kg' => [500, 365, '100.00'],
]);

it('charges the COD fee on COD minus the delivery charge', function (): void {
    $quote = steadfast()->quote(steadfastRequest(['weightGrams' => 500, 'codAmount' => Money::of('1070.00')]));

    expect($quote->codFee->toDecimal())->toBe('10.00')->and($quote->etaDays)->toBeInt();
});

it('makes no HTTP call to quote', function (): void {
    Http::fake();
    steadfast()->quote(steadfastRequest());
    Http::assertNothingSent();
});

it('resolves a bulk-submitted shipment by invoice: id from trackings_by_invoice, status from status_by_invoice', function (): void {
    Http::fake([
        SF_BASE.'/trackings_by_invoice/SHP-26-000001' => steadfastResponse('trackings_by_invoice'),
        SF_BASE.'/status_by_invoice/SHP-26-000001' => steadfastResponse('status_by_invoice', ['delivery_status' => 'pending']),
    ]);

    $result = steadfast()->statusByMerchantRef('SHP-26-000001');

    expect($result?->consignmentId)->toBe('170577767')
        ->and($result?->status)->toBe(ShipmentStatus::InTransit)
        ->and($result?->rawStatus)->toBe('pending')
        ->and($result?->message)->toBe('Consignment status has been updated as Pending');
});

it('answers null for an invoice Steadfast does not know yet', function (): void {
    Http::fake([SF_BASE.'/trackings_by_invoice/SHP-26-000009' => steadfastResponse('trackings_by_invoice.empty')]);

    expect(steadfast()->statusByMerchantRef('SHP-26-000009'))->toBeNull();
    Http::assertSentCount(1);
});
