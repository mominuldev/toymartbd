<?php

declare(strict_types=1);

namespace App\Domain\Inbox\DTOs;

use App\Domain\Inbox\Enums\DeliveryStatus;
use Illuminate\Support\Carbon;

/**
 * Messenger's delivery/read watermark: every outbound message in the thread sent before
 * `$watermark` reached `$status` (Messenger message_reads / message_deliveries webhooks).
 */
final readonly class WatermarkUpdate
{
    public function __construct(
        public string $threadId,
        public DeliveryStatus $status,
        public Carbon $watermark,
    ) {}
}
