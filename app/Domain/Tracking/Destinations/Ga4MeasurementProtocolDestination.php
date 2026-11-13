<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Destinations;

use App\Domain\Tracking\Contracts\TrackingDestination;
use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\DispatchResult;
use App\Domain\Tracking\DTOs\TrackingEventData;
use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Exceptions\RetryableTrackingFailure;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Domain\Tracking\Support\DecimalNumber;
use App\Domain\Tracking\Support\SecretScrubber;
use App\Domain\Tracking\Support\UserDataHasher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use stdClass;

/**
 * Google Analytics 4 Measurement Protocol (web / gtag.js stream).
 *
 *   POST {base}/mp/collect?measurement_id=…&api_secret=…        JSON body
 *   POST {base}/debug/mp/collect?…   validation server: returns {validationMessages: […]},
 *                                    events never reach reports
 *
 * https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference
 * https://developers.google.com/analytics/devguides/collection/protocol/ga4/validating-events
 *
 * IMPORTANT: the production endpoint answers 2xx whenever the HTTP request is received —
 * it "doesn't return an error code if the payload is malformed". A `Sent` result from
 * send() therefore means "Google accepted the request", NOT "the event is valid". Only
 * validate() (the debug endpoint) proves a payload; the admin "Send test event" uses it.
 *
 * The api_secret sits in the query string because that is the only place the protocol
 * accepts it. It is never returned: sentPayload is the body only, responses and errors
 * are passed through SecretScrubber, and a ConnectionException's message (which carries
 * the URL) is never propagated.
 */
final readonly class Ga4MeasurementProtocolDestination implements TrackingDestination
{
    public function __construct(private Http $http, private TrackingSettings $settings) {}

    public function key(): string
    {
        return 'ga4';
    }

    public function isConfigured(): bool
    {
        return $this->settings->ga4Enabled()
            && self::filled($this->settings->ga4MeasurementId())
            && self::filled($this->settings->ga4ApiSecret());
    }

    /** GA4 is analytics: sendable only with analytics consent. */
    public function requiresConsent(ConsentState $c): bool
    {
        return $c->analytics;
    }

    public function send(TrackingEventData $e, bool $testMode = false): DispatchResult
    {
        return $this->dispatch($e, debugEndpoint: false, testMode: $testMode);
    }

    public function validate(TrackingEventData $e): DispatchResult
    {
        return $this->dispatch($e, debugEndpoint: true, testMode: true);
    }

    /**
     * The request body exactly as sent. Public so the admin/tests can inspect a mapping
     * without a network call. Contains hashed user data only and no secret.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(TrackingEventData $e, bool $testMode = false): array
    {
        $params = [];

        if ($e->currency !== null && $e->currency !== '') {
            $params['currency'] = $e->currency;
        }

        $value = DecimalNumber::tryToJsonNumber($e->value);
        if ($value !== null) {
            // "currency is required if you set value" — reference/events.
            $params['value'] = $value;
            $params['currency'] ??= 'BDT';
        }

        if ($e->transactionId !== null && $e->transactionId !== '') {
            $params['transaction_id'] = $e->transactionId;
        }

        if ($e->items !== []) {
            $params['items'] = array_map(self::item(...), $e->items);
        }

        if ($e->eventSourceUrl !== null && $e->eventSourceUrl !== '') {
            $params['page_location'] = $e->eventSourceUrl;
        }

        // session_id: "A positive number … must match ^\d+$" (reference). From the
        // browser's _ga_<container> cookie via the storefront; omitted when absent.
        if ($e->gaSessionId !== null && preg_match('/^\d+$/', $e->gaSessionId) === 1) {
            $params['session_id'] = $e->gaSessionId;
            // Reference recommends engagement_time_msec alongside session_id so the event
            // counts towards engaged sessions; a server event has no measured engagement,
            // so we send the minimal positive value. Our choice, documented in tracking.md §4.
            $params['engagement_time_msec'] = 1;
        }

        if ($testMode) {
            // UNVERIFIED: `debug_mode` routes the event to GA4 DebugView. The MP reference
            // pages we could fetch (2026-11-13) do not list it; it is the gtag.js parameter.
            $params['debug_mode'] = true;
        }

        $body = [
            'client_id' => self::clientId($e),
        ];

        if ($e->externalId !== null && $e->externalId !== '') {
            // user_id must not be PII (GA policy). TrackingEventData::$externalId is our
            // customer id, never a phone or email — see tracking.md §4.
            $body['user_id'] = $e->externalId;
        }

        // "Unix timestamp, microseconds"; events "can be backdated up to 72 hours".
        // The tracking queue never sends anything older than 1 h (SYSTEM-ARCHITECTURE §7).
        $body['timestamp_micros'] = (int) $e->occurredAt->format('Uu');

        // consent.ad_user_data / ad_personalization: "GRANTED" | "DENIED" (reference).
        $adsGranted = $e->consent->ads ? 'GRANTED' : 'DENIED';
        $body['consent'] = ['ad_user_data' => $adsGranted, 'ad_personalization' => $adsGranted];

        // User-provided data is ad data: only with ads consent (ad_user_data GRANTED).
        if ($e->consent->ads) {
            $userData = $this->userData($e);
            if ($userData !== []) {
                $body['user_data'] = $userData;
            }
        }

        $body['events'] = [[
            'name' => $e->name->ga4Name(),
            'params' => $params === [] ? new stdClass : $params,
        ]];

        return $body;
    }

    private function dispatch(TrackingEventData $e, bool $debugEndpoint, bool $testMode): DispatchResult
    {
        if (! $this->isConfigured()) {
            return new DispatchResult(DispatchStatus::SkippedDisabled, error: 'GA4 is not configured or not enabled.');
        }

        $payload = $this->buildPayload($e, $testMode);
        $secret = (string) $this->settings->ga4ApiSecret();
        $base = rtrim((string) config('tracking.ga4.base_url', 'https://www.google-analytics.com'), '/');
        $url = $base.($debugEndpoint ? '/debug/mp/collect' : '/mp/collect');

        try {
            $response = $this->http
                ->timeout((int) config('tracking.ga4.timeout', 10))
                ->acceptJson()
                ->asJson()
                ->withQueryParameters([
                    'measurement_id' => (string) $this->settings->ga4MeasurementId(),
                    'api_secret' => $secret,
                ])
                ->post($url, $payload);
        } catch (ConnectionException) {
            // The exception message contains the full URL, api_secret included — never reuse it.
            throw new RetryableTrackingFailure('GA4: could not reach the Measurement Protocol endpoint.', 'ga4');
        }

        return $this->interpret($response, $payload, $secret, $debugEndpoint);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function interpret(Response $response, array $payload, string $secret, bool $debugEndpoint): DispatchResult
    {
        $status = $response->status();
        $excerpt = SecretScrubber::excerpt($response->body(), [$secret]);

        if ($status === 429 || $status >= 500) {
            $retryAfter = $response->header('Retry-After');

            throw new RetryableTrackingFailure(
                "GA4: HTTP {$status} from the Measurement Protocol endpoint.",
                'ga4',
                $status,
                is_numeric($retryAfter) ? (int) $retryAfter : null,
            );
        }

        if (! $response->successful()) {
            return new DispatchResult(DispatchStatus::Failed, $status, $excerpt, $payload, "GA4: HTTP {$status}.");
        }

        if (! $debugEndpoint) {
            // 2xx = received, not validated (see class docblock).
            return new DispatchResult(DispatchStatus::Sent, $status, $excerpt === '' ? null : $excerpt, $payload);
        }

        $json = $response->json();
        $messages = is_array($json) && is_array($json['validationMessages'] ?? null) ? $json['validationMessages'] : null;

        if ($messages === null) {
            return new DispatchResult(DispatchStatus::Failed, $status, $excerpt, $payload, 'GA4: unexpected validation-server response.');
        }

        if ($messages === []) {
            return new DispatchResult(DispatchStatus::Sent, $status, $excerpt, $payload);
        }

        $lines = [];
        foreach ($messages as $message) {
            if (is_array($message)) {
                $lines[] = trim(sprintf(
                    '%s %s: %s',
                    (string) ($message['validationCode'] ?? ''),
                    (string) ($message['fieldPath'] ?? ''),
                    (string) ($message['description'] ?? ''),
                ));
            }
        }

        return new DispatchResult(
            DispatchStatus::Failed,
            $status,
            $excerpt,
            $payload,
            SecretScrubber::excerpt('GA4 validation: '.implode(' | ', $lines), [$secret]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function userData(TrackingEventData $e): array
    {
        $data = [];

        $email = UserDataHasher::ga4Email($e->email);
        if ($email !== null) {
            $data['sha256_email_address'] = [$email];
        }

        $phone = UserDataHasher::ga4Phone($e->phoneE164);
        if ($phone !== null) {
            $data['sha256_phone_number'] = [$phone];
        }

        $address = array_filter([
            'sha256_first_name' => UserDataHasher::ga4Name($e->firstName),
            'sha256_last_name' => UserDataHasher::ga4Name($e->lastName),
            'city' => UserDataHasher::ga4Text($e->city),
        ], static fn (?string $v): bool => $v !== null);

        if ($address !== []) {
            // country: ISO 3166-1 alpha-2, not hashed. Every order ships within Bangladesh.
            $address['country'] = 'BD';
            $data['address'] = [$address];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function item(array $item): array
    {
        $mapped = [
            'item_id' => (string) ($item['item_id'] ?? ''),
            'item_name' => (string) ($item['item_name'] ?? ''),
        ];

        $price = DecimalNumber::tryToJsonNumber(isset($item['price']) ? (string) $item['price'] : null);
        if ($price !== null) {
            $mapped['price'] = $price;
        }

        $mapped['quantity'] = (int) ($item['quantity'] ?? 1);

        foreach (['item_brand', 'item_category', 'item_variant'] as $key) {
            if (isset($item[$key]) && $item[$key] !== '') {
                $mapped[$key] = (string) $item[$key];
            }
        }

        return $mapped;
    }

    /**
     * client_id must "match the ID generated by the Google Analytics tag on your website"
     * (sending-events). The storefront passes either the raw `_ga` cookie
     * ("GA1.1.123456789.1700000000") or the bare id ("123456789.1700000000"); both become
     * the bare id.
     *
     * When there is none (gtag blocked, consent given only after the cookie would have been
     * set, or a server-only purchase), the docs do not say what to send. client_id is
     * required, so we send a STABLE server-generated id in the same two-number shape,
     * derived from the external id (one per customer) or, failing that, the event id. This
     * starts a separate GA user for that visitor — a known, documented loss of attribution,
     * not an error. UNVERIFIED: that GA treats this shape identically to a tag-generated id.
     */
    public static function clientId(TrackingEventData $e): string
    {
        $raw = $e->gaClientId !== null ? trim($e->gaClientId) : '';

        if (preg_match('/^GA\d+\.\d+\.(\d+\.\d+)$/', $raw, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/^\d+\.\d+$/', $raw) === 1) {
            return $raw;
        }

        $seed = ($e->externalId !== null && $e->externalId !== '') ? 'customer:'.$e->externalId : 'event:'.$e->eventId;
        $hash = hash('sha256', 'toymart-ga4-client:'.$seed);

        // Two decimal numbers from the hash: 9 and 10 digits, like the tag's random.timestamp.
        $first = 100000000 + (hexdec(substr($hash, 0, 7)) % 900000000);
        $second = 1000000000 + (hexdec(substr($hash, 7, 8)) % 9000000000);

        return $first.'.'.$second;
    }

    private static function filled(?string $v): bool
    {
        return $v !== null && trim($v) !== '';
    }
}
