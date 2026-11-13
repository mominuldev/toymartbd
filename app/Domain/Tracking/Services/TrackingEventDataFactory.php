<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Services;

use App\Domain\Ordering\Models\Order;
use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\TrackingClientContext;
use App\Domain\Tracking\DTOs\TrackingEventData;
use App\Domain\Tracking\Models\OrderTrackingContext;
use App\Domain\Tracking\Models\TrackingEvent;
use Carbon\CarbonImmutable;

/**
 * Rebuilds the full {@see TrackingEventData} a driver needs from a PII-free
 * `tracking_events` row, at dispatch time:
 *
 * - browser-relayed events: the client context the storefront forwarded (carried in the job);
 * - order events (purchase, refund): the order's own contact data (raw here; the driver
 *   hashes it) plus `order_tracking_contexts` when the checkout sent one and it has not been
 *   redacted. A staff-entered order has no browser context: server context only.
 */
final readonly class TrackingEventDataFactory
{
    public function __construct(private DispatchPolicy $policy) {}

    public function make(TrackingEvent $row, ?TrackingClientContext $client = null, ?string $eventSourceUrl = null): TrackingEventData
    {
        $payload = $row->payload ?? [];
        $order = $row->order_id !== null ? Order::query()->with('customer')->find($row->order_id) : null;
        $context = $order !== null ? OrderTrackingContext::query()->where('order_id', $order->id)->first() : null;
        if ($client === null && $context !== null && $context->redacted_at === null) {
            $client = new TrackingClientContext($context->client_ip, $context->user_agent, $context->ga_client_id, $context->ga_session_id, $context->fbp, $context->fbc);
            $eventSourceUrl ??= $context->event_source_url;
        }
        $client ??= new TrackingClientContext;

        [$first, $last] = $order !== null ? self::splitName($order->shipping_name) : [null, null];
        $url = $eventSourceUrl ?? (is_string($payload['event_source_url'] ?? null) ? $payload['event_source_url'] : null);
        /** @var list<array{item_id:string,item_name:string,price:string,quantity:int,item_brand?:string,item_category?:string,item_variant?:string}> $items */
        $items = is_array($payload['items'] ?? null) ? array_values($payload['items']) : [];

        return new TrackingEventData(
            eventId: $row->event_id,
            name: $row->event_name,
            occurredAt: CarbonImmutable::instance($row->occurred_at ?? $row->created_at ?? now()),
            currency: $row->currency,
            value: $row->value?->toDecimal(),
            items: $items,
            transactionId: is_string($payload['transaction_id'] ?? null) ? $payload['transaction_id'] : null,
            eventSourceUrl: $url,
            clientIp: $client->ip,
            userAgent: $client->userAgent,
            gaClientId: $client->gaClientId,
            gaSessionId: $client->gaSessionId,
            fbp: $client->fbp,
            fbc: $client->fbc,
            phoneE164: $order?->shipping_phone,
            email: $order?->customer?->email,
            firstName: $first,
            lastName: $last,
            city: $order?->shipping_district,
            externalId: $order !== null ? (string) $order->customer_id : ($row->customer_id !== null ? (string) $row->customer_id : null),
            consent: $this->consent($row),
        );
    }

    private function consent(TrackingEvent $row): ConsentState
    {
        return DispatchPolicy::consentFromArray($row->consent_state) ?? $this->policy->serverConsent();
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        if ($parts === [] || $parts[0] === '') {
            return [null, null];
        }
        $first = array_shift($parts);

        return [$first, $parts === [] ? null : implode(' ', $parts)];
    }
}
