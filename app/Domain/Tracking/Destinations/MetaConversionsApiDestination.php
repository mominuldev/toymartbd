<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Destinations;

use App\Domain\Tracking\Contracts\TrackingDestination;
use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\DispatchResult;
use App\Domain\Tracking\DTOs\TrackingEventData;
use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Enums\TrackingEventName;
use App\Domain\Tracking\Exceptions\RetryableTrackingFailure;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Domain\Tracking\Support\DecimalNumber;
use App\Domain\Tracking\Support\SecretScrubber;
use App\Domain\Tracking\Support\UserDataHasher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;

/**
 * Meta Conversions API.
 *
 *   POST https://graph.facebook.com/{version}/{pixel_id}/events
 *   body {data: [event], access_token, test_event_code?}
 *   → 200 {events_received, messages, fbtrace_id}
 *   → 4xx/5xx {error: {message, type, code, error_subcode, fbtrace_id}}
 *
 * https://developers.facebook.com/docs/marketing-api/conversions-api/using-the-api
 * https://developers.facebook.com/docs/marketing-api/conversions-api/parameters
 * https://developers.facebook.com/docs/graph-api/guides/error-handling
 *
 * access_token goes in the POST body (the official curl example sends it as a body field),
 * never in the URL, so it cannot leak through a URL in a log or exception. It is never in
 * sentPayload, which is the body minus access_token.
 *
 * Dedup: Meta discards a server event whose event_id + event_name match a Pixel event
 * received within 48 h, so event_id is ALWAYS the browser's eventID.
 * https://developers.facebook.com/docs/marketing-api/conversions-api/deduplicate-pixel-and-server-events
 *
 * test_event_code: events sent with it "also flow into Events Manager and are used for
 * targeting and ads measurement" — there is no dry-run. So validate() and a test-mode send
 * REFUSE without a configured test code instead of sending a real event.
 */
final readonly class MetaConversionsApiDestination implements TrackingDestination
{
    /**
     * Graph error codes the error-handling guide says to "wait and retry": 1 API Unknown,
     * 2 API Service, 4 / 17 too many calls, 341 application limit reached.
     */
    private const array RETRYABLE_GRAPH_CODES = [1, 2, 4, 17, 341];

    public function __construct(private Http $http, private TrackingSettings $settings) {}

    public function key(): string
    {
        return 'meta';
    }

    public function isConfigured(): bool
    {
        return $this->settings->metaEnabled()
            && self::filled($this->settings->metaPixelId())
            && self::filled($this->settings->metaAccessToken());
    }

    /** Meta is advertising: sendable only with ads consent. */
    public function requiresConsent(ConsentState $c): bool
    {
        return $c->ads;
    }

    public function send(TrackingEventData $e, bool $testMode = false): DispatchResult
    {
        return $this->dispatch($e, $testMode);
    }

    public function validate(TrackingEventData $e): DispatchResult
    {
        return $this->dispatch($e, true);
    }

    /**
     * The request body as sent, WITHOUT access_token. Null when the event is not sent to
     * Meta (refund).
     *
     * @return array<string, mixed>|null
     */
    public function buildPayload(TrackingEventData $e, ?string $testEventCode = null): ?array
    {
        $eventName = $e->name->metaName();
        if ($eventName === null) {
            return null;
        }

        $event = [
            'event_name' => $eventName,
            // Unix SECONDS; may be up to 7 days old (server-event parameters).
            'event_time' => $e->occurredAt->getTimestamp(),
            'event_id' => $e->eventId,
            'action_source' => 'website',
        ];

        // "required for website events shared using the Conversions API".
        if ($e->eventSourceUrl !== null && $e->eventSourceUrl !== '') {
            $event['event_source_url'] = $e->eventSourceUrl;
        }

        $event['user_data'] = $this->userData($e);

        $customData = $this->customData($e);
        if ($customData !== []) {
            $event['custom_data'] = $customData;
        }

        $body = ['data' => [$event]];

        if ($testEventCode !== null && $testEventCode !== '') {
            $body['test_event_code'] = $testEventCode;
        }

        return $body;
    }

    private function dispatch(TrackingEventData $e, bool $testMode): DispatchResult
    {
        if ($e->name->metaName() === null) {
            return new DispatchResult(DispatchStatus::NotApplicable, error: "Meta has no standard event for {$e->name->value}.");
        }

        if (! $this->isConfigured()) {
            return new DispatchResult(DispatchStatus::SkippedDisabled, error: 'Meta Conversions API is not configured or not enabled.');
        }

        $testCode = null;
        if ($testMode) {
            $testCode = $this->settings->metaTestEventCode();
            if (! self::filled($testCode)) {
                return new DispatchResult(
                    DispatchStatus::Failed,
                    error: 'Meta: set a test event code (Events Manager → Test events) before sending a test event; without one the event would count as real.',
                );
            }
        }

        $payload = $this->buildPayload($e, $testCode);
        if ($payload === null) {
            return new DispatchResult(DispatchStatus::NotApplicable);
        }

        $token = (string) $this->settings->metaAccessToken();
        $url = sprintf(
            '%s/%s/%s/events',
            rtrim((string) config('tracking.meta.base_url', 'https://graph.facebook.com'), '/'),
            rawurlencode($this->settings->metaGraphVersion()),
            rawurlencode((string) $this->settings->metaPixelId()),
        );

        try {
            $response = $this->http
                ->timeout((int) config('tracking.meta.timeout', 10))
                ->acceptJson()
                ->asJson()
                ->post($url, $payload + ['access_token' => $token]);
        } catch (ConnectionException) {
            throw new RetryableTrackingFailure('Meta: could not reach the Conversions API.', 'meta');
        }

        return $this->interpret($response, $payload, $token);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function interpret(Response $response, array $payload, string $token): DispatchResult
    {
        $status = $response->status();
        $excerpt = SecretScrubber::excerpt($response->body(), [$token]);
        $json = $response->json();
        $error = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : null;
        $graphCode = $error !== null && is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;

        if ($status === 429 || $status >= 500 || ($graphCode !== null && in_array($graphCode, self::RETRYABLE_GRAPH_CODES, true))) {
            $retryAfter = $response->header('Retry-After');

            throw new RetryableTrackingFailure(
                SecretScrubber::scrub(
                    "Meta: HTTP {$status}".($graphCode !== null ? " (Graph error {$graphCode})" : '').' from the Conversions API.',
                    [$token],
                ),
                'meta',
                $status,
                is_numeric($retryAfter) ? (int) $retryAfter : null,
            );
        }

        if ($response->successful() && $error === null) {
            $received = is_array($json) && is_numeric($json['events_received'] ?? null) ? (int) $json['events_received'] : null;

            if ($received === null || $received < 1) {
                return new DispatchResult(DispatchStatus::Failed, $status, $excerpt, $payload, 'Meta: response did not confirm events_received.');
            }

            return new DispatchResult(DispatchStatus::Sent, $status, $excerpt, $payload);
        }

        $message = 'Meta: HTTP '.$status;
        if ($error !== null) {
            $message = sprintf(
                'Meta error %s%s: %s',
                (string) ($error['code'] ?? '?'),
                isset($error['error_subcode']) ? '/'.(string) $error['error_subcode'] : '',
                (string) ($error['error_user_msg'] ?? $error['message'] ?? 'unknown error'),
            );
            if (isset($error['fbtrace_id'])) {
                $message .= ' (fbtrace_id '.(string) $error['fbtrace_id'].')';
            }
        }

        return new DispatchResult(DispatchStatus::Failed, $status, $excerpt, $payload, SecretScrubber::excerpt($message, [$token]));
    }

    /**
     * Customer information parameters. Hashed: em, ph, fn, ln, ct, country, external_id.
     * NOT hashed (Meta: "Do not hash"): client_ip_address, client_user_agent, fbc, fbp.
     *
     * @return array<string, mixed>
     */
    private function userData(TrackingEventData $e): array
    {
        $hashed = [
            'em' => UserDataHasher::metaEmail($e->email),
            'ph' => UserDataHasher::metaPhone($e->phoneE164),
            'fn' => UserDataHasher::metaName($e->firstName),
            'ln' => UserDataHasher::metaName($e->lastName),
            'ct' => UserDataHasher::metaCity($e->city),
            // "Include it even if all records are from one country." Every order is in BD.
            'country' => UserDataHasher::metaCountry('bd'),
            'external_id' => UserDataHasher::metaExternalId($e->externalId),
        ];

        $data = [];
        foreach ($hashed as $key => $hash) {
            if ($hash !== null) {
                $data[$key] = [$hash];
            }
        }

        $plain = [
            'client_ip_address' => $e->clientIp !== null ? trim($e->clientIp) : null,
            'client_user_agent' => $e->userAgent,
            'fbc' => $e->fbc !== null ? trim($e->fbc) : null,   // case-sensitive: never lower-cased
            'fbp' => $e->fbp !== null ? trim($e->fbp) : null,
        ];

        foreach ($plain as $key => $value) {
            if ($value !== null && $value !== '') {
                $data[$key] = $value;
            }
        }

        // client_ip_address must be a valid IPv4/IPv6 address.
        if (isset($data['client_ip_address']) && filter_var($data['client_ip_address'], FILTER_VALIDATE_IP) === false) {
            unset($data['client_ip_address']);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function customData(TrackingEventData $e): array
    {
        $data = [];

        $value = DecimalNumber::tryToJsonNumber($e->value);
        if ($value !== null) {
            $data['value'] = $value;
        }

        // ISO 4217; "Required for purchase events".
        if ($e->currency !== null && $e->currency !== '') {
            $data['currency'] = $e->currency;
        } elseif ($value !== null || $e->name === TrackingEventName::Purchase) {
            $data['currency'] = 'BDT';
        }

        if ($e->items !== []) {
            $data['content_ids'] = array_map(static fn (array $i): string => $i['item_id'], $e->items);
            $data['contents'] = array_map(static function (array $i): array {
                $content = ['id' => $i['item_id'], 'quantity' => $i['quantity']];
                $price = DecimalNumber::tryToJsonNumber($i['price']);
                if ($price !== null) {
                    $content['item_price'] = $price;
                }

                return $content;
            }, $e->items);
            $data['content_type'] = 'product';
        }

        if ($e->transactionId !== null && $e->transactionId !== '') {
            $data['order_id'] = $e->transactionId;
        }

        // "Use only with InitiateCheckout events."
        if ($e->name === TrackingEventName::BeginCheckout && $e->items !== []) {
            $data['num_items'] = array_sum(array_map(static fn (array $i): int => $i['quantity'], $e->items));
        }

        return $data;
    }

    private static function filled(?string $v): bool
    {
        return $v !== null && trim($v) !== '';
    }
}
