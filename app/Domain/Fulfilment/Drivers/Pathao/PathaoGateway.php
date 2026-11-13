<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Drivers\Pathao;

use App\Domain\Fulfilment\Contracts\CourierGateway;
use App\Domain\Fulfilment\Contracts\ShapesWebhookResponse;
use App\Domain\Fulfilment\DTOs\BookingResult;
use App\Domain\Fulfilment\DTOs\BulkBookingResult;
use App\Domain\Fulfilment\DTOs\CourierLocationRef;
use App\Domain\Fulfilment\DTOs\CourierQuote;
use App\Domain\Fulfilment\DTOs\CourierStatusResult;
use App\Domain\Fulfilment\DTOs\CourierWebhookEvent;
use App\Domain\Fulfilment\DTOs\ShipmentRequest;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Locations\ProvidesCourierLocations;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Pathao Courier merchant API driver (rule 5). Paths, fields and the webhook contract come
 * from docs/_research/agent3-appbyte-and-couriers.md §5; response shapes were OBSERVED on
 * the sandbox on 2026-11-13 and are pinned as fixtures in tests/Fixtures/Pathao/.
 *
 *   quote   → POST /merchant/price-plan   (needs store, city, zone — never an area)
 *   book    → POST /orders                (city/zone/area from a human-mapped courier_locations row)
 *   bookMany→ POST /orders/bulk           (202, no consignment ids — rule 24d)
 *   status  → GET  /orders/{id}/info      (a snapshot; there is no timeline endpoint)
 *
 * Location: Pathao can geocode a bare address when city/zone are omitted, but that is
 * Pathao guessing the zone, so we refuse instead (rule 32): no mapped location, no booking.
 *
 * Webhook: `X-PATHAO-Signature` is the literal shared secret (rule 24c, not an HMAC), so
 * verification is a constant-time equality, and the event is only a trigger to re-query
 * `status()`. Every webhook response must carry {@see self::INTEGRATION_HEADER} =
 * {@see self::INTEGRATION_SECRET} (a global constant, not our secret), and the
 * `webhook_integration` handshake must be answered 202 — supplied through
 * {@see ShapesWebhookResponse} and applied by ReceiveCourierWebhook.
 */
final class PathaoGateway implements CourierGateway, ProvidesCourierLocations, ShapesWebhookResponse
{
    public const string CODE = 'pathao';

    public const string SIGNATURE_HEADER = 'X-PATHAO-Signature';

    public const string INTEGRATION_HEADER = 'X-Pathao-Merchant-Webhook-Integration-Secret';

    public const string INTEGRATION_SECRET = 'f3992ecc-59da-4cbe-a049-a13da2018d51';

    public const string HANDSHAKE_EVENT = 'webhook_integration';

    /** Pathao's enums (research §5.4, Tier A): 48 Normal delivery, 2 Parcel. */
    private const int DELIVERY_NORMAL = 48;

    private const int ITEM_PARCEL = 2;

    /** Pathao's API weight bounds in kg (research §5.4). */
    private const float MIN_WEIGHT_KG = 0.5;

    private const float MAX_WEIGHT_KG = 10.0;

    private ?PathaoClient $client = null;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Cache $cache,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function requiresLocationMapping(): bool
    {
        return true;
    }

    public function supportsBulk(): bool
    {
        return true;
    }

    public function quote(ShipmentRequest $r): CourierQuote
    {
        $location = $r->courierLocation;
        if (! self::hasCityAndZone($location)) {
            throw PathaoException::unmapped();
        }

        $response = $this->client()->post('/merchant/price-plan', [
            'store_id' => $this->storeId(),
            'item_type' => self::ITEM_PARCEL,
            'delivery_type' => self::DELIVERY_NORMAL,
            'item_weight' => self::weightKg($r->weightGrams),
            'recipient_city' => (int) $location->externalCityId,
            'recipient_zone' => (int) $location->externalZoneId,
        ], 'price plan');

        if (! $response->successful()) {
            throw new PathaoException('Pathao refused the price plan: '.self::errorText($response), PathaoException::MALFORMED);
        }

        $price = self::money($response->json('data.final_price'));
        $codRate = self::decimal($response->json('data.cod_percentage'));
        if ($price === null || $codRate === null) {
            throw PathaoException::malformed('price plan');
        }

        return new CourierQuote($price, $r->codAmount->times($codRate));
    }

    public function book(ShipmentRequest $r): BookingResult
    {
        $payload = $this->orderPayload($r);
        if (is_string($payload)) {
            return BookingResult::refused($payload);
        }

        $response = $this->client()->post('/orders', $payload, 'order create');

        if (! $response->successful()) {
            return new BookingResult(false, error: self::errorText($response), response: self::body($response));
        }

        $consignmentId = $response->json('data.consignment_id');
        if (! is_string($consignmentId) || $consignmentId === '') {
            throw PathaoException::malformed('order create');
        }

        $status = $response->json('data.order_status');

        return new BookingResult(
            accepted: true,
            consignmentId: $consignmentId,
            trackingCode: $consignmentId,
            charge: self::money($response->json('data.delivery_fee')),
            providerStatusRaw: is_string($status) ? $status : null,
            response: self::body($response),
        );
    }

    public function bookMany(array $rs): BulkBookingResult
    {
        $orders = [];
        $refused = [];
        foreach ($rs as $r) {
            $payload = $this->orderPayload($r);
            is_string($payload) ? $refused[] = $r->merchantOrderRef.': '.$payload : $orders[] = $payload;
        }

        // All or nothing: Pathao's bulk answer names no order, so a partial batch could not be reconciled.
        if ($refused !== []) {
            return new BulkBookingResult(false, 'Not submitted. '.implode('; ', $refused));
        }
        if ($orders === []) {
            return new BulkBookingResult(false, 'Nothing to submit.');
        }

        $response = $this->client()->post('/orders/bulk', ['orders' => $orders], 'bulk order create');

        if (! $response->successful()) {
            return new BulkBookingResult(false, self::errorText($response));
        }

        return new BulkBookingResult(true, refs: array_map(static fn (ShipmentRequest $r): string => $r->merchantOrderRef, $rs));
    }

    public function status(string $consignmentId): CourierStatusResult
    {
        $response = $this->client()->get('/orders/'.rawurlencode($consignmentId).'/info', 'order info');

        if ($response->status() === 404) {
            throw PathaoException::notFound($consignmentId);
        }
        if (! $response->successful()) {
            throw new PathaoException('Pathao refused the order info: '.self::errorText($response), PathaoException::MALFORMED);
        }

        $raw = $response->json('data.order_status');
        if (! is_string($raw) || $raw === '') {
            throw PathaoException::malformed('order info');
        }

        $status = self::normalise($raw);

        return new CourierStatusResult(
            status: $status,
            rawStatus: $raw,
            occurredAt: self::dhakaTime($response->json('data.updated_at')),
            consignmentId: is_string($response->json('data.consignment_id')) ? $response->json('data.consignment_id') : $consignmentId,
            trackingCode: $consignmentId,
        );
    }

    public function fetchLocations(): array
    {
        return (new PathaoLocationSource($this->client()))->fetch();
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        $secret = PathaoCredentials::resolve()?->webhookSecret;
        if ($secret === null || $secret === '') {
            return false;
        }

        $given = null;
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, self::SIGNATURE_HEADER) === 0) {
                $given = is_array($value) ? ($value[0] ?? null) : $value;
                break;
            }
        }

        return is_string($given) && hash_equals($secret, $given);
    }

    /**
     * An order event → the consignment to re-query. Store events and the handshake carry no
     * consignment and return null ({@see self::isHandshake()} tells the two apart).
     */
    public function parseWebhook(string $rawBody): ?CourierWebhookEvent
    {
        $data = json_decode($rawBody, true);
        if (! is_array($data)) {
            return null;
        }

        $event = $data['event'] ?? null;
        $consignmentId = $data['consignment_id'] ?? null;
        if (! is_string($event) || ! str_starts_with($event, 'order.') || ! is_string($consignmentId) || $consignmentId === '') {
            return null;
        }

        $merchantRef = $data['merchant_order_id'] ?? null;
        $instant = $data['timestamp'] ?? $data['updated_at'] ?? '';

        return new CourierWebhookEvent(
            $consignmentId,
            is_string($merchantRef) && $merchantRef !== '' ? $merchantRef : null,
            $event,
            // Pathao sends no event id: consignment + event + instant identifies a delivery attempt.
            hash('sha256', $consignmentId.'|'.$event.'|'.(is_string($instant) ? $instant : '')),
        );
    }

    /** Pathao answers 202 to the handshake and to every event (research §5.5). */
    public function webhookAckStatus(): int
    {
        return 202;
    }

    /**
     * Echoed on EVERY webhook response, a 401 included — without it Pathao's panel
     * verification fails. A global constant, not our secret.
     *
     * @return array<string, string>
     */
    public function webhookAckHeaders(): array
    {
        return [self::INTEGRATION_HEADER => self::INTEGRATION_SECRET];
    }

    public static function isHandshake(string $rawBody): bool
    {
        $data = json_decode($rawBody, true);

        return is_array($data) && ($data['event'] ?? null) === self::HANDSHAKE_EVENT;
    }

    /**
     * Every documented Pathao status, keyed by its normalised form ({@see self::key()}), so
     * one table serves the webhook wire events (`order.pickup-requested`), the plugin's display
     * statuses (`Pickup_Requested`, `At_the_Sorting_HUB`) and order-info's `order_status` /
     * `order_status_slug` (`Pending` / `pending`, observed). Sources: research §5.5 table
     * (Pathao's own plugin `$orderEventsStatusMap`) — the sandbox only showed `Pending`.
     *
     * Judgement calls, flagged for the owner:
     *   - `pickup_failed` and `delivery_failed` → on_hold: the parcel needs a human, and
     *     Pathao usually re-attempts; a later event moves it on.
     *   - `paid` / `payment_invoice` (COD invoiced) → delivered; the transition table keeps a
     *     partially_delivered parcel from being "upgraded" by it.
     *   - `exchange` → delivered (the new item reached the customer); the exchanged-back item
     *     is a return the order team records.
     *   - `paid_return` → returned (customer paid the delivery charge, refused the parcel).
     *   - `updated` (order edited) → booked: informational; the guard refuses a regression.
     *
     * @return array<string, ShipmentStatus>
     */
    public static function statusMap(): array
    {
        return [
            'pending' => ShipmentStatus::Booked,
            'created' => ShipmentStatus::Booked,
            'updated' => ShipmentStatus::Booked,
            'pickup_requested' => ShipmentStatus::Booked,
            'assigned_for_pickup' => ShipmentStatus::Booked,
            'picked' => ShipmentStatus::PickedUp,
            'pickup_failed' => ShipmentStatus::OnHold,
            'pickup_cancelled' => ShipmentStatus::Cancelled,
            'at_the_sorting_hub' => ShipmentStatus::InTransit,
            'in_transit' => ShipmentStatus::InTransit,
            'received_at_last_mile_hub' => ShipmentStatus::InTransit,
            'assigned_for_delivery' => ShipmentStatus::OutForDelivery,
            'delivered' => ShipmentStatus::Delivered,
            'partial_delivery' => ShipmentStatus::PartiallyDelivered,
            'return' => ShipmentStatus::Returned,
            'returned' => ShipmentStatus::Returned,
            'delivery_failed' => ShipmentStatus::OnHold,
            'on_hold' => ShipmentStatus::OnHold,
            'paid' => ShipmentStatus::Delivered,
            'payment_invoice' => ShipmentStatus::Delivered,
            'paid_return' => ShipmentStatus::Returned,
            'exchange' => ShipmentStatus::Delivered,
            'exchanged' => ShipmentStatus::Delivered,
        ];
    }

    /**
     * Every documented raw spelling — webhook `event` values and the plugin's display
     * statuses (research §5.5). The status-map test asserts each one normalises.
     *
     * @return list<string>
     */
    public static function documentedStatuses(): array
    {
        return [
            // webhook wire events (order.*)
            'order.created', 'order.updated', 'order.pickup-requested', 'order.assigned-for-pickup',
            'order.picked', 'order.pickup-failed', 'order.pickup-cancelled', 'order.at-the-sorting-hub',
            'order.in-transit', 'order.received-at-last-mile-hub', 'order.assigned-for-delivery',
            'order.delivered', 'order.partial-delivery', 'order.returned', 'order.delivery-failed',
            'order.on-hold', 'order.paid', 'order.paid-return', 'order.exchanged',
            // display statuses (Pathao plugin) and order-info values
            'Pending', 'pending', 'Order_Created', 'Order_Updated', 'Pickup_Requested', 'Assigned_for_Pickup',
            'Picked', 'Pickup_Failed', 'Pickup_Cancelled', 'At_the_Sorting_HUB', 'In_Transit',
            'Received_at_Last_Mile_HUB', 'Assigned_for_Delivery', 'Delivered', 'Partial_Delivery', 'Return',
            'Delivery_Failed', 'On_Hold', 'Payment_Invoice', 'paid_return', 'exchange',
        ];
    }

    /** `order.pickup-requested`, `Pickup_Requested`, `Pickup Requested` → `pickup_requested`. */
    public static function key(string $raw): string
    {
        $key = strtolower(trim($raw));
        $key = (string) preg_replace('/[^a-z0-9]+/', '_', $key);
        $key = trim($key, '_');

        return (string) preg_replace('/^order_/', '', $key);
    }

    public static function normalise(string $raw): ShipmentStatus
    {
        return self::statusMap()[self::key($raw)] ?? throw PathaoException::unknownStatus($raw);
    }

    /**
     * The `POST /orders` body, or a refusal reason. City and zone come only from a mapped
     * location; `recipient_area` is omitted (never null) when the mapping is zone-level.
     *
     * @return array<string, mixed>|string
     */
    private function orderPayload(ShipmentRequest $r): array|string
    {
        $location = $r->courierLocation;
        if (! self::hasCityAndZone($location)) {
            return PathaoException::unmapped()->getMessage();
        }

        $kg = $r->weightGrams / 1000;
        if ($kg > self::MAX_WEIGHT_KG) {
            return 'Pathao takes at most 10 kg per parcel; split this shipment.';
        }

        if (preg_match('/^\d+(\.00)?$/', $r->codAmount->toDecimal()) !== 1) {
            return 'Pathao collects whole taka only; this cash-on-delivery amount has poisha ('.$r->codAmount->toDecimal().').';
        }

        $name = mb_substr(trim($r->recipientName), 0, 64);
        if (mb_strlen($name) < 3) {
            return 'Pathao needs a recipient name of at least 3 characters.';
        }

        $phone = self::localPhone($r->recipientPhone);
        if ($phone === null) {
            return 'The recipient phone is not a valid Bangladeshi mobile number.';
        }

        $address = trim($r->addressLine);
        if ($r->landmark !== null && trim($r->landmark) !== '') {
            $address .= ', Landmark: '.trim($r->landmark);
        }
        $address = mb_substr($address, 0, 220);
        if (mb_strlen($address) < 10) {
            return 'Pathao needs an address of at least 10 characters.';
        }

        $payload = [
            'store_id' => $this->storeId(),
            'merchant_order_id' => $r->merchantOrderRef,
            'recipient_name' => $name,
            'recipient_phone' => $phone,
            'recipient_address' => $address,
            'recipient_city' => (int) $location->externalCityId,
            'recipient_zone' => (int) $location->externalZoneId,
            'delivery_type' => self::DELIVERY_NORMAL,
            'item_type' => self::ITEM_PARCEL,
            'item_quantity' => max(1, $r->itemCount),
            'item_weight' => self::weightKg($r->weightGrams),
            'item_description' => mb_substr($r->itemDescription, 0, 255),
            'amount_to_collect' => (int) $r->codAmount->toDecimal(),
        ];
        if ($location->externalAreaId !== null && $location->externalAreaId !== '') {
            $payload['recipient_area'] = (int) $location->externalAreaId;
        }
        if ($r->note !== null && trim($r->note) !== '') {
            $payload['special_instruction'] = mb_substr(trim($r->note), 0, 255);
        }

        return $payload;
    }

    private function client(): PathaoClient
    {
        if ($this->client === null) {
            $credentials = PathaoCredentials::resolve() ?? throw PathaoException::notConfigured();
            $this->client = new PathaoClient($this->http, $this->cache, $credentials);
        }

        return $this->client;
    }

    private function storeId(): int
    {
        return $this->client()->credentials()->storeId
            ?? throw new PathaoException('Pathao needs a pickup store id (credentials.store_id).', PathaoException::NOT_CONFIGURED);
    }

    /** @phpstan-assert-if-true CourierLocationRef $location */
    private static function hasCityAndZone(?CourierLocationRef $location): bool
    {
        return $location !== null
            && ctype_digit((string) $location->externalCityId)
            && ctype_digit((string) $location->externalZoneId)
            && ($location->externalAreaId === null || $location->externalAreaId === '' || ctype_digit($location->externalAreaId));
    }

    private static function weightKg(int $grams): float
    {
        return max(self::MIN_WEIGHT_KG, round($grams / 1000, 2));
    }

    /** E.164 `+8801XXXXXXXXX` (how we store phones, rule 30) → Pathao's 11-digit `01XXXXXXXXX`. */
    private static function localPhone(string $phone): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        }

        return preg_match('/^01[3-9]\d{8}$/', $digits) === 1 ? $digits : null;
    }

    private static function money(mixed $value): ?Money
    {
        $decimal = self::decimal($value);

        return $decimal === null ? null : Money::of($decimal);
    }

    private static function decimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            $text = (string) $value;

            return preg_match('/^-?\d+(\.\d+)?$/', $text) === 1 ? $text : sprintf('%.4F', $value);
        }
        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', trim($value)) === 1) {
            return trim($value);
        }

        return null;
    }

    /** Pathao's `updated_at` is a naive Asia/Dhaka wall-clock time (research §5.5). */
    private static function dhakaTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value, 'Asia/Dhaka') ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Pathao's error message plus field errors (`errors` is an object, or a string on 404). */
    private static function errorText(Response $response): string
    {
        $message = $response->json('message');
        $text = is_string($message) && $message !== '' ? $message : 'HTTP '.$response->status();

        $errors = $response->json('errors');
        if (is_array($errors)) {
            $flat = [];
            array_walk_recursive($errors, static function (mixed $e) use (&$flat): void {
                if (is_string($e)) {
                    $flat[] = (string) preg_replace('/\s+/', ' ', $e);
                }
            });
            if ($flat !== []) {
                $text .= ': '.implode(' ', $flat);
            }
        }

        return $text;
    }

    /** @return array<string, mixed>|null */
    private static function body(Response $response): ?array
    {
        $json = $response->json();

        return is_array($json) ? $json : null;
    }
}
