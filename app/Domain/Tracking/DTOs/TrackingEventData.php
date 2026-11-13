<?php

declare(strict_types=1);

namespace App\Domain\Tracking\DTOs;

use App\Domain\Tracking\Enums\TrackingEventName;
use Carbon\CarbonImmutable;

/**
 * Everything a destination needs for one event. PII is still RAW here — the drivers
 * normalise and hash it. Never persist or log this object as-is.
 */
final readonly class TrackingEventData
{
    /**
     * @param  string  $eventId  UUID shared with the browser event
     * @param  string|null  $currency  ISO 4217, 'BDT'
     * @param  string|null  $value  decimal string, 2dp — never float
     * @param  list<array{item_id:string,item_name:string,price:string,quantity:int,item_brand?:string,item_category?:string,item_variant?:string}>  $items
     * @param  string|null  $transactionId  order number for purchase/refund
     * @param  string|null  $externalId  customer id or phone-derived id
     */
    public function __construct(
        public string $eventId,
        public TrackingEventName $name,
        public CarbonImmutable $occurredAt,
        public ?string $currency,
        public ?string $value,
        public array $items,
        public ?string $transactionId,
        public ?string $eventSourceUrl,
        public ?string $clientIp,
        public ?string $userAgent,
        public ?string $gaClientId,
        public ?string $gaSessionId,
        public ?string $fbp,
        public ?string $fbc,
        public ?string $phoneE164,
        public ?string $email,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $city,
        public ?string $externalId,
        public ConsentState $consent,
    ) {}
}
