<?php

declare(strict_types=1);

namespace App\Domain\Tracking\DTOs;

use App\Domain\Tracking\Enums\TrackingEventName;
use App\Domain\Tracking\Enums\TrackingSource;
use Carbon\CarbonImmutable;

/**
 * What {@see \App\Domain\Tracking\Actions\RecordTrackingEvent} writes: the event without
 * any personal data. Raw PII never travels in this object (rule 20) — the dispatch job
 * re-reads it from the order, or receives the client context separately.
 */
final readonly class TrackingEventRecord
{
    /**
     * @param  list<array{item_id:string,item_name:string,price:string,quantity:int,item_brand?:string,item_category?:string,item_variant?:string}>  $items
     */
    public function __construct(
        public string $eventId,
        public TrackingEventName $name,
        public TrackingSource $source,
        public CarbonImmutable $occurredAt,
        public ConsentState $consent,
        public ?string $value = null,
        public ?string $currency = null,
        public array $items = [],
        public ?string $transactionId = null,
        public ?string $eventSourceUrl = null,
        public ?int $orderId = null,
        public ?int $customerId = null,
        public ?string $sessionId = null,
    ) {}
}
