<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

/**
 * A verified webhook, reduced to what identifies the shipment. It is a trigger to
 * re-query `status()`, never a source of truth about money or delivery (rules 19, 24c).
 */
final readonly class CourierWebhookEvent
{
    public function __construct(
        public string $consignmentId,
        public ?string $merchantOrderRef,
        public string $rawStatus,
        public ?string $eventId = null,
    ) {}
}
