<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Inbox\Events\WhatsAppMessageStatusReceived;
use App\Domain\Notification\Actions\RecordWhatsAppDeliveryStatus;
use App\Domain\Notification\Enums\WhatsAppDeliveryStatus;

/**
 * A WhatsApp status the Inbox's webhook could not match to a conversation message: it may be
 * one of our order-update templates (same WhatsApp number). Runs inside the webhook-processing
 * job, after its commit; no provider call is made here.
 */
final readonly class ApplyWhatsAppDeliveryStatus
{
    public function __construct(private RecordWhatsAppDeliveryStatus $record) {}

    public function handle(WhatsAppMessageStatusReceived $event): void
    {
        $status = WhatsAppDeliveryStatus::tryFrom($event->status);
        if ($status === null) {
            return;
        }

        $this->record->handle($event->providerMessageId, $status, $event->occurredAt, $event->errorCode, $event->errorTitle);
    }
}
