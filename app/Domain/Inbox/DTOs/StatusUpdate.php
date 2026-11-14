<?php

declare(strict_types=1);

namespace App\Domain\Inbox\DTOs;

use App\Domain\Inbox\Enums\DeliveryStatus;
use Illuminate\Support\Carbon;

/** A delivery status for one of OUR outbound messages, by the provider's message id. */
final readonly class StatusUpdate
{
    public function __construct(
        public string $providerMessageId,
        public DeliveryStatus $status,
        public Carbon $at,
        public ?string $errorCode = null,
        public ?string $errorTitle = null,
    ) {}
}
