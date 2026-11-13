<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\TrackingEventData;
use App\Domain\Tracking\Enums\TrackingEventName;
use Carbon\CarbonImmutable;

/**
 * Tracking destination fixtures. Provider responses are shaped from the OFFICIAL docs
 * (no real account exists — every send in tests is Http::fake):
 *   GA4 validation server: {"validationMessages":[{fieldPath, description, validationCode}]}
 *     https://developers.google.com/analytics/devguides/collection/protocol/ga4/validating-events
 *   Meta success: {"events_received":1,"messages":[],"fbtrace_id":"…"}
 *     https://developers.facebook.com/docs/marketing-api/reference/ads-pixel/events/
 *   Graph error: {"error":{message,type,code,error_subcode,fbtrace_id}}
 *     https://developers.facebook.com/docs/graph-api/guides/error-handling
 */
final class TrackingFixtures
{
    public const string GA4_SECRET = 'ga4-SECRET-do-not-leak';

    public const string META_TOKEN = 'EAAmetaTOKENdoNotLeak123';

    public static function configure(bool $withTestCode = true): void
    {
        config([
            'tracking.server_side_enabled' => true,
            'tracking.ga4.enabled' => true,
            'tracking.ga4.measurement_id' => 'G-TEST123',
            'tracking.ga4.api_secret' => self::GA4_SECRET,
            'tracking.meta.enabled' => true,
            'tracking.meta.pixel_id' => '1234567890',
            'tracking.meta.access_token' => self::META_TOKEN,
            'tracking.meta.test_event_code' => $withTestCode ? 'TEST12345' : null,
            'tracking.meta.graph_version' => 'v27.0',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function event(TrackingEventName $name = TrackingEventName::Purchase, array $overrides = []): TrackingEventData
    {
        $args = array_merge([
            'eventId' => '6f1c1a52-7e6b-5b0e-9a7c-0d9d2f7a1b11',
            'name' => $name,
            'occurredAt' => CarbonImmutable::parse('2026-11-13 10:00:00.123456', 'UTC'),
            'currency' => 'BDT',
            'value' => '1999.90',
            'items' => [
                ['item_id' => 'TMB-LEGO-01', 'item_name' => 'Lego Classic', 'price' => '999.95', 'quantity' => 2, 'item_brand' => 'LEGO', 'item_category' => 'Blocks'],
            ],
            'transactionId' => 'TMB-261113-0001',
            'eventSourceUrl' => 'https://toymartbd.com/checkout',
            'clientIp' => '103.4.145.2',
            'userAgent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0',
            'gaClientId' => 'GA1.1.1234567890.1731490000',
            'gaSessionId' => '1731490000',
            'fbp' => 'fb.1.1596403881668.1116446470',
            'fbc' => 'fb.1.1554763741205.AbCdEfGhIjKlMnOpQrStUvWxYz1234567890',
            'phoneE164' => '01712345678',
            'email' => '  Mixed.Case@Example.COM ',
            'firstName' => 'Rahim',
            'lastName' => 'Uddin',
            'city' => 'Dhaka',
            'externalId' => 'cust-42',
            'consent' => new ConsentState(analytics: true, ads: true, mode: 'opt_in', policyVersion: '2026-11'),
        ], $overrides);

        return new TrackingEventData(...$args);
    }

    /** @return array<string, mixed> */
    public static function metaSuccess(): array
    {
        return ['events_received' => 1, 'messages' => [], 'fbtrace_id' => 'AbCdTrace123'];
    }

    /** @return array<string, mixed> */
    public static function metaError(int $code = 100, ?int $subcode = 2804003, string $message = 'Invalid parameter'): array
    {
        return ['error' => array_filter([
            'message' => $message,
            'type' => 'OAuthException',
            'code' => $code,
            'error_subcode' => $subcode,
            'fbtrace_id' => 'AbCdTrace456',
        ], static fn ($v): bool => $v !== null)];
    }

    /** @return array<string, mixed> */
    public static function ga4ValidationFailure(): array
    {
        return ['validationMessages' => [[
            'fieldPath' => 'events',
            'description' => 'Event at index: [0] has invalid name [_badname]. Names must start with an alphabetic character.',
            'validationCode' => 'NAME_INVALID',
        ]]];
    }
}
