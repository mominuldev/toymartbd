<?php

declare(strict_types=1);

use App\Domain\Tracking\Destinations\MetaConversionsApiDestination;
use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\DispatchResult;
use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Enums\TrackingEventName;
use App\Domain\Tracking\Exceptions\RetryableTrackingFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TrackingFixtures as TF;

/*
 * Meta Conversions API driver. Http::fake only — no test reaches Meta.
 * https://developers.facebook.com/docs/marketing-api/conversions-api/using-the-api
 */

const META_URL = 'https://graph.facebook.com/v27.0/1234567890/events';

beforeEach(function (): void {
    Http::preventStrayRequests();
    TF::configure();
});

function meta(): MetaConversionsApiDestination
{
    return app(MetaConversionsApiDestination::class);
}

function metaRequest(): Request
{
    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(1);

    return $recorded[0][0];
}

function assertNoMetaToken(DispatchResult $r): void
{
    $haystack = json_encode($r->sentPayload).($r->responseExcerpt ?? '').($r->error ?? '');
    expect($haystack)->not->toContain(TF::META_TOKEN)->not->toContain('access_token');
}

it('posts a purchase to /{version}/{pixel}/events with the token in the body, never the URL', function (): void {
    Http::fake([META_URL => Http::response(TF::metaSuccess())]);

    $result = meta()->send(TF::event());
    $request = metaRequest();

    expect($request->url())->toBe(META_URL)
        ->and($request->url())->not->toContain(TF::META_TOKEN)
        ->and($request->data()['access_token'])->toBe(TF::META_TOKEN)
        ->and($request->data())->not->toHaveKey('test_event_code')
        ->and($result->status)->toBe(DispatchStatus::Sent)
        ->and($result->httpStatus)->toBe(200)
        ->and($result->responseExcerpt)->toContain('events_received');

    $event = $request->data()['data'][0];
    expect($event['event_name'])->toBe('Purchase')
        ->and($event['event_time'])->toBe(1794564000)
        ->and($event['event_id'])->toBe('6f1c1a52-7e6b-5b0e-9a7c-0d9d2f7a1b11')
        ->and($event['action_source'])->toBe('website')
        ->and($event['event_source_url'])->toBe('https://toymartbd.com/checkout')
        ->and($event['custom_data'])->toBe([
            'value' => 1999.9,
            'currency' => 'BDT',
            'content_ids' => ['TMB-LEGO-01'],
            'contents' => [['id' => 'TMB-LEGO-01', 'quantity' => 2, 'item_price' => 999.95]],
            'content_type' => 'product',
            'order_id' => 'TMB-261113-0001',
        ]);

    expect($request->body())->toContain('"value":1999.9,');

    // sentPayload = body as sent minus the token.
    $sent = $request->data();
    unset($sent['access_token']);
    expect($result->sentPayload)->toBe($sent);
    assertNoMetaToken($result);
});

it('hashes customer information per Meta\'s rules and sends ip/ua/fbp/fbc unhashed', function (): void {
    Http::fake([META_URL => Http::response(TF::metaSuccess())]);

    meta()->send(TF::event());
    $userData = metaRequest()->data()['data'][0]['user_data'];

    expect($userData)->toBe([
        // 01712345678 → 8801712345678 before hashing
        'em' => ['7a126a993c9ece5663288f1e48a453a6b4b12656af38d84103cb6beb8a5862b9'],
        'ph' => ['c327520f85b0c6058fed05dfc0a63d8755f325b6ea1b550824f4a8e380d75de1'],
        'fn' => [hash('sha256', 'rahim')],
        'ln' => [hash('sha256', 'uddin')],
        'ct' => ['de90643718108ec9a93cc6905f2b976297505a8abeac37b747132877fb32fb8d'],
        'country' => ['5e657ff6158d3e2a6d23e2a523917a2305acee9423365e268695c4b7b8919f4c'],
        'external_id' => ['8a3c5a67cad508582b5edf6b8352cea3ffbad7f44812c1a736b4444c0f5746aa'],
        'client_ip_address' => '103.4.145.2',
        'client_user_agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0',
        'fbc' => 'fb.1.1554763741205.AbCdEfGhIjKlMnOpQrStUvWxYz1234567890',
        'fbp' => 'fb.1.1596403881668.1116446470',
    ]);

    expect(metaRequest()->body())->not->toContain('Mixed.Case')->not->toContain('01712345678')->not->toContain('Rahim');
});

it('maps event names and adds num_items only to InitiateCheckout', function (TrackingEventName $name, string $metaName): void {
    Http::fake([META_URL => Http::response(TF::metaSuccess())]);

    meta()->send(TF::event($name));
    $event = metaRequest()->data()['data'][0];

    expect($event['event_name'])->toBe($metaName);
    if ($name === TrackingEventName::BeginCheckout) {
        expect($event['custom_data']['num_items'])->toBe(2);
    } else {
        expect($event['custom_data'])->not->toHaveKey('num_items');
    }
})->with([
    [TrackingEventName::PageView, 'PageView'],
    [TrackingEventName::ViewItem, 'ViewContent'],
    [TrackingEventName::AddToCart, 'AddToCart'],
    [TrackingEventName::BeginCheckout, 'InitiateCheckout'],
    [TrackingEventName::Purchase, 'Purchase'],
]);

it('never sends a refund to Meta', function (): void {
    $result = meta()->send(TF::event(TrackingEventName::Refund));

    expect($result->status)->toBe(DispatchStatus::NotApplicable);
    Http::assertNothingSent();
});

it('needs ads consent', function (): void {
    expect(meta()->requiresConsent(new ConsentState(false, true, 'opt_in')))->toBeTrue()
        ->and(meta()->requiresConsent(new ConsentState(true, false, 'opt_in')))->toBeFalse();
});

it('validate() sends test_event_code', function (): void {
    Http::fake([META_URL => Http::response(TF::metaSuccess())]);

    $result = meta()->validate(TF::event());

    expect(metaRequest()->data()['test_event_code'])->toBe('TEST12345')
        ->and($result->sentPayload['test_event_code'])->toBe('TEST12345')
        ->and($result->status)->toBe(DispatchStatus::Sent);
});

it('a test-mode send also carries test_event_code', function (): void {
    Http::fake([META_URL => Http::response(TF::metaSuccess())]);

    meta()->send(TF::event(), testMode: true);

    expect(metaRequest()->data()['test_event_code'])->toBe('TEST12345');
});

it('refuses validate() without a test event code instead of sending a real event', function (): void {
    TF::configure(withTestCode: false);

    $result = meta()->validate(TF::event());

    expect($result->status)->toBe(DispatchStatus::Failed)->and($result->error)->toContain('test event code');
    Http::assertNothingSent();
});

it('fails a 400 with Meta\'s error message, without throwing or leaking the token', function (): void {
    Http::fake([META_URL => Http::response(TF::metaError(100, 2804003, 'Invalid parameter '.TF::META_TOKEN), 400)]);

    $result = meta()->send(TF::event());

    expect($result->status)->toBe(DispatchStatus::Failed)
        ->and($result->httpStatus)->toBe(400)
        ->and($result->error)->toContain('Meta error 100/2804003')->toContain('Invalid parameter')->toContain('AbCdTrace456');
    assertNoMetaToken($result);
});

it('fails a 200 that does not confirm events_received', function (): void {
    Http::fake([META_URL => Http::response(['events_received' => 0, 'messages' => []])]);

    expect(meta()->send(TF::event())->status)->toBe(DispatchStatus::Failed);
});

it('throws retryable on 5xx, 429 and Graph throttling codes', function (int $status, array $body): void {
    Http::fake([META_URL => Http::response($body, $status)]);

    try {
        meta()->send(TF::event());
        $this->fail('expected RetryableTrackingFailure');
    } catch (RetryableTrackingFailure $e) {
        expect($e->destination)->toBe('meta')->and($e->httpStatus)->toBe($status)
            ->and($e->getMessage())->not->toContain(TF::META_TOKEN);
    }
})->with([
    [500, ['error' => ['message' => 'x', 'code' => 2]]],
    [503, []],
    [429, []],
    [400, ['error' => ['message' => 'User request limit reached', 'code' => 17]]],
]);

it('throws retryable on a connection error', function (): void {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect(fn () => meta()->send(TF::event()))->toThrow(RetryableTrackingFailure::class);
});

it('drops an invalid client IP rather than sending it', function (): void {
    Http::fake([META_URL => Http::response(TF::metaSuccess())]);

    meta()->send(TF::event(overrides: ['clientIp' => 'not-an-ip']));

    expect(metaRequest()->data()['data'][0]['user_data'])->not->toHaveKey('client_ip_address');
});

it('skips without a call when not configured', function (): void {
    config(['tracking.meta.access_token' => null]);

    expect(meta()->isConfigured())->toBeFalse()
        ->and(meta()->send(TF::event())->status)->toBe(DispatchStatus::SkippedDisabled);
    Http::assertNothingSent();
});
