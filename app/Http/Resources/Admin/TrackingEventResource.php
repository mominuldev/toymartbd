<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Tracking\Models\TrackingEvent;
use App\Domain\Tracking\Queries\TrackingDiagnostics;
use App\Http\Resources\ApiResource;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One row of the tracking event log (PRD K-03). Per destination: the dispatch status, the
 * attempt count and the provider's error text (scrubbed of secrets at write time). The
 * payload is NOT in the list — {@see TrackingEventDetailResource} has it. `value` is a
 * decimal string (rule 6).
 *
 * @property TrackingEvent $resource
 */
#[SchemaName('TrackingEvent')]
final class TrackingEventResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $event = $this->resource;
        $consent = $event->consent_state ?? [];

        return [
            'id' => $event->id,
            /** @var string UUID shared by the browser and server copies of the event. */
            'event_id' => $event->event_id,
            /** @var 'page_view'|'view_item'|'add_to_cart'|'begin_checkout'|'purchase'|'refund' */
            'event_name' => $event->event_name->value,
            /** @var 'browser'|'server' */
            'source' => $event->source->value,
            'order_id' => $event->order_id,
            /** @var string|null */
            'order_number' => $event->order_id !== null ? $event->order?->getAttribute('number') : null,
            /** @var string|null Decimal string, 2 dp. */
            'value' => $event->value?->toDecimal(),
            /** @var string|null */
            'currency' => $event->currency,
            /** @var array{analytics: bool|null, ads: bool|null, mode: string|null} */
            'consent' => [
                'analytics' => isset($consent['analytics']) ? (bool) $consent['analytics'] : null,
                'ads' => isset($consent['ads']) ? (bool) $consent['ads'] : null,
                'mode' => $consent['mode'] ?? null,
            ],
            /** @var array{status: 'pending'|'sent'|'failed'|'skipped_consent'|'skipped_disabled'|'skipped_stale'|'not_applicable', attempts: int, error: string|null} */
            'ga4' => self::destination($event, 'ga4'),
            /** @var array{status: 'pending'|'sent'|'failed'|'skipped_consent'|'skipped_disabled'|'skipped_stale'|'not_applicable', attempts: int, error: string|null} */
            'meta' => self::destination($event, 'meta'),
            /** @var string|null */
            'occurred_at' => self::utc($event->occurred_at),
            /** @var string|null */
            'dispatched_at' => self::utc($event->dispatched_at),
            /** @var string|null */
            'created_at' => self::utc($event->created_at),
        ];
    }

    /** @return array{status: string, attempts: int, error: ?string} */
    public static function destination(TrackingEvent $event, string $destination): array
    {
        $attempts = $event->getAttribute($destination.'_attempts');

        return [
            'status' => $event->statusFor($destination)->value,
            'attempts' => is_int($attempts) ? $attempts : 0,
            'error' => TrackingDiagnostics::errorText($event->getAttribute($destination.'_response')),
        ];
    }

    public static function utc(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}
