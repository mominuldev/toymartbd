<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Drivers\Steadfast;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Contracts\CourierGateway;
use App\Domain\Fulfilment\Contracts\FraudLookup;
use App\Domain\Fulfilment\Contracts\LooksUpByMerchantRef;
use App\Domain\Fulfilment\DTOs\BookingResult;
use App\Domain\Fulfilment\DTOs\BulkBookingResult;
use App\Domain\Fulfilment\DTOs\CourierQuote;
use App\Domain\Fulfilment\DTOs\CourierStatusResult;
use App\Domain\Fulfilment\DTOs\CourierWebhookEvent;
use App\Domain\Fulfilment\DTOs\FraudLookupResult;
use App\Domain\Fulfilment\DTOs\ShipmentRequest;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Domain\Fulfilment\Services\DeliverySettings;
use App\Support\Money;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;

/**
 * Steadfast Courier (docs/_research/agent3-appbyte-and-couriers.md §6; docs/api/couriers.md).
 *
 * FIXTURE-ONLY. No Steadfast merchant account exists and Steadfast has NO sandbox
 * (rule 33a), so every behaviour here is built from its published Postman collection, the
 * official `steadfast-it` Laravel package and a verbatim copy of its API guide, and is
 * tested against hand-authored fixtures in tests/Fixtures/Steadfast/. The provider row
 * stays `verified = false`; production booking through it is refused here as well as in
 * the booking job.
 *
 * API: `https://portal.packzy.com/api/v1`, static `Api-Key` + `Secret-Key` headers on
 * every call (from `courier_providers.credentials`, encrypted — rule 20).
 *  - book       POST /create_order            invoice = our shipment ref (unique-enforced
 *               by Steadfast, so a retry after a timeout cannot double-book)
 *  - bookMany   POST /create_order/bulk-order  {"data": "<JSON-encoded array>"}, ≤ 500 per
 *               request → chunked; result is only "submitted" (rule 24d)
 *  - status     GET  /status_by_cid/{id}       {"status":200,"delivery_status":"…"}
 *  - by ref     GET  /trackings_by_invoice/{invoice} (consignment id) +
 *               GET  /status_by_invoice/{invoice}   (status) — resolves a bulk-submitted
 *               shipment, which has no consignment id yet (rule 24d)
 *  - balance    GET  /get_balance              {"status":200,"current_balance":-10}
 *  - fraud      GET  /fraud_check/{phone}      official; needs a merchant account (33b)
 *
 * Address: free text only — Steadfast has no city/zone/area ids (§3.6), so no location
 * mapping. Price: Steadfast publishes no price API, so `quote()` reads our stored rate
 * card, stepping at 1 kg (rule 32a — never Pathao's 2 kg).
 *
 * Transport failures (timeout, 5xx, 429, non-JSON) THROW {@see SteadfastUnavailable};
 * definite refusals (4xx, 401) are RETURNED. A 401 is never retried: Steadfast locks a
 * key out for an hour after ten auth failures in five minutes (research §6.10).
 */
final class SteadfastGateway implements CourierGateway, FraudLookup, LooksUpByMerchantRef
{
    public const string CODE = 'steadfast';

    public const string DEFAULT_BASE_URL = 'https://portal.packzy.com/api/v1';

    public const int BULK_LIMIT = 500;

    /** The guide's older, stricter limits (research §6.3) — safe under either guide. */
    private const int MAX_NAME = 100;

    private const int MAX_ADDRESS = 250;

    private const int MAX_NOTE = 480;

    private const int MAX_ITEM_DESCRIPTION = 255;

    private ?CourierProvider $provider = null;

    public function __construct(
        private readonly Http $http,
        private readonly DeliveryChargeResolver $zones,
        private readonly DeliverySettings $settings,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function requiresLocationMapping(): bool
    {
        return false;
    }

    public function supportsBulk(): bool
    {
        return true;
    }

    /** @return array<string, ShipmentStatus> */
    public static function statusMap(): array
    {
        return SteadfastStatusMap::MAP;
    }

    /**
     * From our stored rate card: Steadfast has no price endpoint (research §6.9). Base charge
     * for the address's zone covers `included_weight_grams` (1000 for Steadfast); each
     * started kilogram above it adds `per_kg_charge` (rule 32a). The COD fee is
     * `cod_fee_percent` of (COD − delivery charge), Steadfast's published basis (agent4
     * §3.2), never below zero.
     */
    public function quote(ShipmentRequest $r): CourierQuote
    {
        $provider = $this->provider();
        $district = $r->districtId !== null ? District::query()->find($r->districtId) : null;
        if ($district === null) {
            throw new InvalidArgumentException('steadfast quote: the shipment has no district, so no zone can be priced.');
        }
        $upazila = $r->upazilaId !== null ? Upazila::query()->find($r->upazilaId) : null;
        $zone = $this->zones->zoneFor($district, $upazila)
            ?? throw new InvalidArgumentException('steadfast quote: the address is not serviced.');

        $charge = $provider->baseChargeFor($zone)->plus($this->weightSurcharge($provider, $r->weightGrams));
        $codBase = $r->codAmount->minus($charge);
        $codFee = $codBase->isNegative() ? Money::zero() : $codBase->percentage($provider->cod_fee_percent);

        return new CourierQuote($charge, $codFee, $this->settings->etaDays($zone)[1]);
    }

    public function book(ShipmentRequest $r): BookingResult
    {
        if (($blocked = $this->bookingBlockedReason()) !== null) {
            return BookingResult::refused($blocked);
        }

        try {
            $payload = $this->orderPayload($r);
        } catch (InvalidArgumentException $e) {
            return BookingResult::refused($e->getMessage());
        }

        $response = $this->send('create_order', fn (PendingRequest $http): Response => $http->post($this->url('/create_order'), $payload));

        if (($refusal = $this->refusal($response)) !== null) {
            return $refusal;
        }

        $body = $this->decode('create_order', $response);
        $consignment = $body['consignment'] ?? null;
        $id = is_array($consignment) ? $this->idString($consignment['consignment_id'] ?? null) : null;

        if (! is_array($consignment) || $id === null) {
            // A 200 without a consignment: unknown whether the order exists. Thrown, so it
            // is retried — the unique invoice makes the retry safe.
            throw SteadfastUnavailable::malformed('create_order', $response->status());
        }

        $tracking = $this->idString($consignment['tracking_code'] ?? null);
        $raw = is_string($consignment['status'] ?? null) ? $consignment['status'] : null;

        return new BookingResult(
            accepted: true,
            consignmentId: $id,
            trackingCode: $tracking,
            // Steadfast's create response carries no charge; the stored rate card prices it.
            charge: null,
            providerStatusRaw: $raw,
            trackingUrl: null,
            response: $body,
        );
    }

    /**
     * Chunked at 500 (the guide's cap). Steadfast's bulk response does list per-entry ids,
     * but rule 24d holds the line for every provider: a bulk-booked shipment is only
     * "submitted" until a webhook or the status poll confirms it, so ids are not taken
     * from here. `refs` holds the invoices Steadfast acknowledged without an entry error.
     *
     * A transport failure on the FIRST chunk throws (nothing was sent). On a later chunk
     * the earlier chunks are already with Steadfast, so the result reports what was
     * acknowledged and names the failure in `error` instead of throwing it away.
     */
    public function bookMany(array $rs): BulkBookingResult
    {
        if ($rs === []) {
            return new BulkBookingResult(false, 'steadfast bulk: nothing to submit.');
        }
        if (($blocked = $this->bookingBlockedReason()) !== null) {
            return new BulkBookingResult(false, $blocked);
        }

        $refs = [];
        $errors = [];
        $payloads = [];
        foreach ($rs as $r) {
            try {
                $payloads[] = $this->orderPayload($r);
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $size = max(1, min(self::BULK_LIMIT, (int) config('couriers.steadfast.bulk_chunk_size', self::BULK_LIMIT)));
        foreach (array_chunk($payloads, $size) as $index => $chunk) {
            try {
                $response = $this->send('bulk-order', fn (PendingRequest $http): Response => $http->post(
                    $this->url('/create_order/bulk-order'),
                    // Variant A: `data` is a JSON-encoded STRING (guide + official SDK).
                    ['data' => json_encode($chunk, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                ));
                if (($refusal = $this->refusal($response)) !== null) {
                    $errors[] = 'chunk '.($index + 1).': '.$refusal->error;

                    continue;
                }
                $entries = $this->decode('bulk-order', $response)['data'] ?? null;
                if (! is_array($entries)) {
                    throw SteadfastUnavailable::malformed('bulk-order', $response->status());
                }
            } catch (SteadfastUnavailable $e) {
                if ($index === 0) {
                    throw $e;
                }
                $errors[] = 'chunk '.($index + 1).': '.$e->getMessage();

                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry) || ! is_string($entry['invoice'] ?? null)) {
                    continue;
                }
                $entryError = $this->entryError($entry);
                if ($entryError === null && ($entry['status'] ?? null) !== 'error') {
                    $refs[] = $entry['invoice'];
                } else {
                    $errors[] = $entry['invoice'].': '.($entryError ?? 'rejected');
                }
            }
        }

        return new BulkBookingResult(
            submitted: $refs !== [],
            error: $errors === [] ? null : implode('; ', array_slice($errors, 0, 50)),
            refs: $refs,
        );
    }

    public function status(string $consignmentId): CourierStatusResult
    {
        $this->credentials() ?? throw new SteadfastUnavailable('steadfast status: no credentials are configured.');

        $response = $this->send('status_by_cid', fn (PendingRequest $http): Response => $http->get($this->url('/status_by_cid/'.rawurlencode($consignmentId))));

        if (! $response->successful()) {
            throw SteadfastUnavailable::http('status_by_cid', $response->status());
        }

        $raw = $this->decode('status_by_cid', $response)['delivery_status'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            throw SteadfastUnavailable::malformed('status_by_cid', $response->status());
        }

        if (! SteadfastStatusMap::isKnown($raw)) {
            Log::warning('courier.steadfast.unknown_status', ['raw' => $raw]);
        }

        return new CourierStatusResult(
            status: SteadfastStatusMap::toShipmentStatus($raw),
            rawStatus: $raw,
            consignmentId: $consignmentId,
        );
    }

    /**
     * Resolve a shipment by the invoice we sent. `status_by_invoice` (the guide) returns
     * only a status, so the consignment id comes from `trackings_by_invoice` (in the
     * official collection), whose events each carry it. An empty event list means
     * Steadfast does not know the invoice (yet) → null. Tracking code is not available
     * from either; it stays null until a later lookup supplies it.
     */
    public function statusByMerchantRef(string $merchantOrderRef): ?CourierStatusResult
    {
        $this->credentials() ?? throw new SteadfastUnavailable('steadfast trackings_by_invoice: no credentials are configured.');
        $invoice = rawurlencode($merchantOrderRef);

        $trackings = $this->send('trackings_by_invoice', fn (PendingRequest $http): Response => $http->get($this->url('/trackings_by_invoice/'.$invoice)));
        if ($trackings->status() === 404) {
            return null;
        }
        if (! $trackings->successful()) {
            throw SteadfastUnavailable::http('trackings_by_invoice', $trackings->status());
        }

        $events = $this->decode('trackings_by_invoice', $trackings)['tracking'] ?? null;
        if (! is_array($events)) {
            throw SteadfastUnavailable::malformed('trackings_by_invoice', $trackings->status());
        }

        $consignmentId = null;
        $message = null;
        foreach ($events as $event) {
            if (is_array($event)) {
                $consignmentId ??= $this->idString($event['consignment_id'] ?? null);
                $message = is_string($event['text'] ?? null) ? $event['text'] : $message;
            }
        }
        if ($consignmentId === null) {
            return null;
        }

        $response = $this->send('status_by_invoice', fn (PendingRequest $http): Response => $http->get($this->url('/status_by_invoice/'.$invoice)));
        if (! $response->successful()) {
            throw SteadfastUnavailable::http('status_by_invoice', $response->status());
        }
        $raw = $this->decode('status_by_invoice', $response)['delivery_status'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            throw SteadfastUnavailable::malformed('status_by_invoice', $response->status());
        }

        return new CourierStatusResult(
            status: SteadfastStatusMap::toShipmentStatus($raw),
            rawStatus: $raw,
            consignmentId: $consignmentId,
            message: $message,
        );
    }

    /** Current merchant balance — can be negative (delivered COD less charges and the 1% fee). */
    public function balance(): Money
    {
        $this->credentials() ?? throw new SteadfastUnavailable('steadfast get_balance: no credentials are configured.');

        $response = $this->send('get_balance', fn (PendingRequest $http): Response => $http->get($this->url('/get_balance')));
        if (! $response->successful()) {
            throw SteadfastUnavailable::http('get_balance', $response->status());
        }

        $value = $this->decode('get_balance', $response)['current_balance'] ?? null;
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw SteadfastUnavailable::malformed('get_balance', $response->status());
        }

        // Rendered through a fixed-precision string; Money rejects a bare float on purpose.
        return Money::of(is_int($value) ? $value : number_format((float) $value, 2, '.', ''));
    }

    /**
     * Steadfast's webhook (research §6.7; NOT in the public collection, configured in the
     * merchant panel with a Callback URL and a Bearer token). Verified before anything is
     * parsed (rule 19):
     *  - `Authorization: Bearer <token>` must equal our stored `webhook_secret` — CONFIRMED
     *    by the official steadfast-it package;
     *  - `X-Signature` (HMAC-SHA256 of the raw body keyed with the token) is described only
     *    by two third parties, so it is checked when present and not required.
     */
    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        $token = $this->provider()->webhook_secret;
        if (! is_string($token) || $token === '') {
            return false;
        }

        $authorization = $this->header($headers, 'authorization');
        if ($authorization === null || ! hash_equals('Bearer '.$token, $authorization)) {
            return false;
        }

        $signature = $this->header($headers, 'x-signature');

        return $signature === null || hash_equals(hash_hmac('sha256', $rawBody, $token), strtolower($signature));
    }

    /**
     * Only `delivery_status` notifications become events — the trigger to re-query
     * `status()`; the body's `cod_amount`, `delivery_charge` and `status` are never trusted
     * (rule 19). The status field is `status`; `delivery_status` is the event NAME.
     * Other notification types (tracking_update, payment_request, …) carry no shipment
     * status and return null.
     */
    public function parseWebhook(string $rawBody): ?CourierWebhookEvent
    {
        try {
            $data = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data) || ($data['notification_type'] ?? null) !== 'delivery_status') {
            return null;
        }

        $id = $this->idString($data['consignment_id'] ?? null);
        $status = $data['status'] ?? null;
        if ($id === null || ! is_string($status) || $status === '') {
            return null;
        }

        $invoice = is_string($data['invoice'] ?? null) ? $data['invoice'] : null;
        $updatedAt = is_string($data['updated_at'] ?? null) ? $data['updated_at'] : '';

        return new CourierWebhookEvent(
            $id,
            $invoice,
            $status,
            'steadfast:'.hash('sha256', implode('|', [$id, $status, $updatedAt])),
        );
    }

    /**
     * `GET /fraud_check/{phone}` — official (in Steadfast's own collection), but it needs a
     * merchant account (rule 33b). Until credentials exist AND the provider is verified the
     * answer is "not connected", never a zero.
     */
    public function fraudLookup(PhoneNumber $phone): FraudLookupResult
    {
        if ($this->credentials() === null) {
            return FraudLookupResult::notConnected('no_merchant_account');
        }
        if (! $this->provider()->verified) {
            return FraudLookupResult::notConnected('provider_unverified');
        }

        try {
            $response = $this->send('fraud_check', fn (PendingRequest $http): Response => $http->get($this->url('/fraud_check/'.$phone->national())));
            if (! $response->successful()) {
                throw SteadfastUnavailable::http('fraud_check', $response->status());
            }
            $body = $this->decode('fraud_check', $response);
        } catch (SteadfastUnavailable $e) {
            Log::warning('courier.steadfast.fraud_check_failed', ['phone' => $phone->masked(), 'error' => $e->getMessage()]);

            return FraudLookupResult::notConnected('provider_error');
        }

        $reports = $body['total_fraud_reports'] ?? null;

        return FraudLookupResult::connected(
            $this->intOrNull($body['total_parcels'] ?? null),
            $this->intOrNull($body['total_delivered'] ?? null),
            $this->intOrNull($body['total_cancelled'] ?? null),
            is_array($reports) ? count($reports) : $this->intOrNull($reports),
            $body,
            CarbonImmutable::now(),
        );
    }

    /**
     * Why booking must not proceed at all, or null. No credentials → nothing to call.
     * Production through an unverified provider is refused (rule 33a) — the booking job
     * enforces this too; the driver repeats it so no other caller can slip past.
     */
    public function bookingBlockedReason(): ?string
    {
        if ($this->credentials() === null) {
            return 'steadfast is not configured: no merchant API credentials are stored.';
        }

        $production = in_array(app()->environment(), (array) config('couriers.production_environments', ['production']), true);
        if ($production && ! $this->provider()->verified) {
            return 'steadfast is unverified: production booking is refused until the driver has been exercised against the live API (rule 33a).';
        }

        return null;
    }

    /**
     * The create_order body for one shipment. Phone as 11 national digits; the landmark
     * rides in the address (it materially reduces failed deliveries — rule 31) or, if the
     * address would pass 250 characters, at the head of the note. `{ } ; < >` are replaced
     * by Steadfast anyway; doing it here keeps what we store equal to what they keep.
     *
     * @return array<string, int|string>
     */
    private function orderPayload(ShipmentRequest $r): array
    {
        $invoice = $r->merchantOrderRef;
        if (preg_match('/^[A-Za-z0-9_-]{1,100}$/', $invoice) !== 1) {
            throw new InvalidArgumentException("steadfast: invoice [{$invoice}] must be 1–100 letters, digits, hyphens or underscores.");
        }

        $phone = PhoneNumber::tryParse($r->recipientPhone)
            ?? throw new InvalidArgumentException("steadfast {$invoice}: the recipient phone is not a valid Bangladeshi mobile number.");

        if ($r->codAmount->isNegative()) {
            throw new InvalidArgumentException("steadfast {$invoice}: COD cannot be negative.");
        }

        $address = $this->clean($r->addressLine);
        $landmark = $r->landmark !== null && trim($r->landmark) !== '' ? $this->clean($r->landmark) : null;
        $note = $r->note !== null && trim($r->note) !== '' ? $this->clean($r->note) : null;

        if ($address === '') {
            throw new InvalidArgumentException("steadfast {$invoice}: the recipient address is empty.");
        }
        if (mb_strlen($address) > self::MAX_ADDRESS) {
            throw new InvalidArgumentException("steadfast {$invoice}: the address is longer than ".self::MAX_ADDRESS.' characters; shorten it rather than let the courier cut it.');
        }

        if ($landmark !== null) {
            $withLandmark = $address.' (Landmark: '.$landmark.')';
            if (mb_strlen($withLandmark) <= self::MAX_ADDRESS) {
                $address = $withLandmark;
            } else {
                $note = 'Landmark: '.$landmark.($note !== null ? ' | '.$note : '');
            }
        }

        $payload = [
            'invoice' => $invoice,
            'recipient_name' => mb_substr($this->clean($r->recipientName), 0, self::MAX_NAME),
            'recipient_phone' => $phone->national(),
            'recipient_address' => $address,
            'cod_amount' => $r->codAmount->toDecimal(),
            'item_description' => mb_substr($this->clean($r->itemDescription), 0, self::MAX_ITEM_DESCRIPTION),
            'total_lot' => max(1, $r->itemCount),
            'delivery_type' => 0, // 0 = home delivery, 1 = Steadfast point/hub pick-up
        ];
        if ($note !== null) {
            $payload['note'] = mb_substr($note, 0, self::MAX_NOTE);
        }

        return $payload;
    }

    /** A definite refusal for 4xx answers (except 429, which is transient), else null. */
    private function refusal(Response $response): ?BookingResult
    {
        $status = $response->status();
        if ($status === 401 || $status === 403) {
            Log::warning('courier.steadfast.auth_failed', ['http' => $status]);

            return BookingResult::refused("steadfast: credentials rejected (HTTP {$status}). Not retried — repeated failures lock the key out for an hour.");
        }
        if ($status >= 400 && $status < 500 && $status !== 429) {
            return BookingResult::refused("steadfast: refused (HTTP {$status}): ".$this->message($response));
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function decode(string $operation, Response $response): array
    {
        try {
            $body = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SteadfastUnavailable::malformed($operation, $response->status());
        }

        if (! is_array($body)) {
            throw SteadfastUnavailable::malformed($operation, $response->status());
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(string $operation, callable $call): Response
    {
        $credentials = $this->credentials() ?? throw new SteadfastUnavailable("steadfast {$operation}: no credentials are configured.");

        try {
            $response = $call($this->http
                ->timeout((int) config('couriers.steadfast.timeout_seconds', 15))
                ->acceptJson()
                ->asJson()
                ->withHeaders(['Api-Key' => $credentials['api_key'], 'Secret-Key' => $credentials['secret_key']]));
        } catch (ConnectionException) {
            throw SteadfastUnavailable::connection($operation);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw SteadfastUnavailable::http($operation, $response->status());
        }

        return $response;
    }

    private function message(Response $response): string
    {
        $body = json_decode($response->body(), true);
        if (! is_array($body)) {
            return 'no readable message';
        }

        $parts = [];
        if (is_string($body['message'] ?? null)) {
            $parts[] = $body['message'];
        }
        if (is_array($body['errors'] ?? null)) {
            foreach ($body['errors'] as $field => $messages) {
                $parts[] = $field.': '.implode(', ', array_filter((array) $messages, 'is_string'));
            }
        }

        return $parts === [] ? 'no readable message' : mb_substr(implode(' | ', array_unique($parts)), 0, 500);
    }

    /**
     * A bulk entry's `error` is a JSON string holding a JSON array (double-encoded) in
     * the collection; the extended endpoint sends a plain array. Both are read.
     *
     * @param  array<mixed>  $entry
     */
    private function entryError(array $entry): ?string
    {
        $error = $entry['error'] ?? null;
        if (is_string($error) && $error !== '') {
            $decoded = json_decode($error, true);
            $error = is_array($decoded) ? $decoded : [$error];
        }
        if (is_array($error)) {
            $messages = array_values(array_filter($error, 'is_string'));

            return $messages === [] ? null : implode(', ', $messages);
        }

        return null;
    }

    /** Consignment ids are integers on the wire and strings everywhere here (§3.6). */
    private function idString(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    private function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(['{', '}', ';', '<', '>'], ' ', $text)));
    }

    /** @param  array<string, list<string>|string>  $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                $value = is_array($value) ? ($value[0] ?? null) : $value;

                return is_string($value) ? trim($value) : null;
            }
        }

        return null;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('couriers.steadfast.base_url', self::DEFAULT_BASE_URL), '/').$path;
    }

    private function weightSurcharge(CourierProvider $provider, int $grams): Money
    {
        $over = $grams - $provider->included_weight_grams;
        if ($over <= 0) {
            return Money::zero();
        }

        return $provider->per_kg_charge->times(intdiv($over + DeliveryChargeResolver::GRAMS_PER_KG - 1, DeliveryChargeResolver::GRAMS_PER_KG));
    }

    /** @return array{api_key: string, secret_key: string}|null */
    private function credentials(): ?array
    {
        $credentials = $this->provider()->credentials ?? [];
        $key = $credentials['api_key'] ?? null;
        $secret = $credentials['secret_key'] ?? null;

        return is_string($key) && $key !== '' && is_string($secret) && $secret !== ''
            ? ['api_key' => $key, 'secret_key' => $secret]
            : null;
    }

    private function provider(): CourierProvider
    {
        return $this->provider ??= CourierProvider::query()->where('code', self::CODE)->first()
            ?? throw new InvalidArgumentException('steadfast: no courier_providers row with code [steadfast].');
    }
}
