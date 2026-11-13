<?php

declare(strict_types=1);

use App\Domain\Tracking\Destinations\Ga4MeasurementProtocolDestination;
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
 * GA4 Measurement Protocol driver. Http::fake only — no test reaches Google.
 * https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    TF::configure();
});

function ga4(): Ga4MeasurementProtocolDestination
{
    return app(Ga4MeasurementProtocolDestination::class);
}

function ga4Request(): Request
{
    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(1);

    return $recorded[0][0];
}

function assertNoGa4Secret(DispatchResult $r): void
{
    $haystack = json_encode($r->sentPayload).($r->responseExcerpt ?? '').($r->error ?? '');
    expect($haystack)->not->toContain(TF::GA4_SECRET);
}

it('posts a purchase to /mp/collect with measurement_id and api_secret in the query', function (): void {
    Http::fake(['https://www.google-analytics.com/mp/collect*' => Http::response('', 204)]);

    $result = ga4()->send(TF::event());

    $request = ga4Request();
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
    expect(parse_url($request->url(), PHP_URL_PATH))->toBe('/mp/collect')
        ->and($query)->toBe(['measurement_id' => 'G-TEST123', 'api_secret' => TF::GA4_SECRET])
        ->and($result->status)->toBe(DispatchStatus::Sent)
        ->and($result->httpStatus)->toBe(204);

    $body = $request->data();
    expect($body['client_id'])->toBe('1234567890.1731490000')
        ->and($body['user_id'])->toBe('cust-42')
        ->and($body['timestamp_micros'])->toBe(1794564000123456)
        ->and($body['consent'])->toBe(['ad_user_data' => 'GRANTED', 'ad_personalization' => 'GRANTED'])
        ->and($body['events'][0]['name'])->toBe('purchase');

    $params = $body['events'][0]['params'];
    expect($params['currency'])->toBe('BDT')
        ->and($params['value'])->toBe(1999.9)
        ->and($params['transaction_id'])->toBe('TMB-261113-0001')
        ->and($params['session_id'])->toBe('1731490000')
        ->and($params['page_location'])->toBe('https://toymartbd.com/checkout')
        ->and($params['items'][0])->toBe([
            'item_id' => 'TMB-LEGO-01', 'item_name' => 'Lego Classic', 'price' => 999.95, 'quantity' => 2,
            'item_brand' => 'LEGO', 'item_category' => 'Blocks',
        ])
        ->and($params)->not->toHaveKey('debug_mode');

    // Money as an exact JSON number — the raw body, not PHP's decoded float.
    expect($request->body())->toContain('"value":1999.9,')->toContain('"price":999.95');

    assertNoGa4Secret($result);
    expect($result->sentPayload)->toBe($body);
});

it('maps each event name to its GA4 name', function (TrackingEventName $name): void {
    Http::fake(['*' => Http::response('', 204)]);

    ga4()->send(TF::event($name));

    expect(ga4Request()->data()['events'][0]['name'])->toBe($name->value);
})->with(TrackingEventName::cases());

it('sends hashed user_data only, per GA4 normalisation, and only with ads consent', function (): void {
    Http::fake(['*' => Http::response('', 204)]);

    ga4()->send(TF::event());
    $userData = ga4Request()->data()['user_data'];

    expect($userData['sha256_email_address'])->toBe([hash('sha256', 'mixed.case@example.com')])
        ->and($userData['sha256_phone_number'])->toBe([hash('sha256', '+8801712345678')])
        ->and($userData['address'][0])->toBe([
            'sha256_first_name' => hash('sha256', 'rahim'),
            'sha256_last_name' => hash('sha256', 'uddin'),
            'city' => 'dhaka',
            'country' => 'BD',
        ]);

    $raw = ga4Request()->body();
    expect($raw)->not->toContain('Mixed.Case')->not->toContain('1712345678"')->not->toContain('Rahim');
});

it('omits user_data and denies ad consent without ads consent', function (): void {
    Http::fake(['*' => Http::response('', 204)]);

    ga4()->send(TF::event(overrides: ['consent' => new ConsentState(analytics: true, ads: false, mode: 'opt_in')]));
    $body = ga4Request()->data();

    expect($body)->not->toHaveKey('user_data')
        ->and($body['consent'])->toBe(['ad_user_data' => 'DENIED', 'ad_personalization' => 'DENIED']);
});

it('needs analytics consent', function (): void {
    expect(ga4()->requiresConsent(new ConsentState(true, false, 'opt_in')))->toBeTrue()
        ->and(ga4()->requiresConsent(new ConsentState(false, true, 'opt_in')))->toBeFalse();
});

it('derives a stable client_id when the GA cookie is absent', function (): void {
    $a = Ga4MeasurementProtocolDestination::clientId(TF::event(overrides: ['gaClientId' => null]));
    $b = Ga4MeasurementProtocolDestination::clientId(TF::event(overrides: ['gaClientId' => null, 'eventId' => 'other']));

    expect($a)->toMatch('/^\d{9}\.\d{10}$/')->and($a)->toBe($b)
        ->and(Ga4MeasurementProtocolDestination::clientId(TF::event(overrides: ['gaClientId' => '111.222'])))->toBe('111.222');
});

it('validate() hits the debug endpoint and returns its validation messages', function (): void {
    Http::fake(['https://www.google-analytics.com/debug/mp/collect*' => Http::response(TF::ga4ValidationFailure())]);

    $result = ga4()->validate(TF::event());

    expect(parse_url(ga4Request()->url(), PHP_URL_PATH))->toBe('/debug/mp/collect')
        ->and($result->status)->toBe(DispatchStatus::Failed)
        ->and($result->error)->toContain('NAME_INVALID')->toContain('invalid name');
    assertNoGa4Secret($result);
});

it('validate() reports Sent when the validation server returns no messages', function (): void {
    Http::fake(['*/debug/mp/collect*' => Http::response(['validationMessages' => []])]);

    expect(ga4()->validate(TF::event())->status)->toBe(DispatchStatus::Sent);
});

it('fails a 4xx without throwing', function (): void {
    Http::fake(['*' => Http::response('bad request api_secret='.TF::GA4_SECRET, 400)]);

    $result = ga4()->send(TF::event());

    expect($result->status)->toBe(DispatchStatus::Failed)->and($result->httpStatus)->toBe(400);
    assertNoGa4Secret($result);
});

it('throws a retryable failure on 5xx and 429, without the secret', function (int $status): void {
    Http::fake(['*' => Http::response('oops', $status, ['Retry-After' => '30'])]);

    try {
        ga4()->send(TF::event());
        $this->fail('expected RetryableTrackingFailure');
    } catch (RetryableTrackingFailure $e) {
        expect($e->httpStatus)->toBe($status)->and($e->destination)->toBe('ga4')
            ->and($e->retryAfterSeconds)->toBe(30)
            ->and($e->getMessage())->not->toContain(TF::GA4_SECRET);
    }
})->with([500, 503, 429]);

it('throws a retryable failure on a connection error without leaking the URL', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://www.google-analytics.com/mp/collect?api_secret='.TF::GA4_SECRET));

    expect(fn () => ga4()->send(TF::event()))
        ->toThrow(fn (RetryableTrackingFailure $e) => expect($e->getMessage())->not->toContain(TF::GA4_SECRET));
});

it('skips without a call when not configured', function (): void {
    config(['tracking.ga4.api_secret' => null]);

    expect(ga4()->isConfigured())->toBeFalse()
        ->and(ga4()->send(TF::event())->status)->toBe(DispatchStatus::SkippedDisabled);
    Http::assertNothingSent();
});
