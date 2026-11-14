<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Jobs;

use App\Domain\Inbox\Actions\ApplyDeliveryStatus;
use App\Domain\Inbox\Actions\IngestInboundMessage;
use App\Domain\Inbox\DTOs\InboundMessage;
use App\Domain\Inbox\DTOs\StatusUpdate;
use App\Domain\Inbox\Models\InboxWebhookReceipt;
use App\Domain\Inbox\Services\InboxChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Parses one signature-verified inbox webhook body and applies each event. Safe to run twice:
 * messages are idempotent on the provider message id, statuses only move forward.
 */
final class ProcessInboxWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $receiptId)
    {
        $this->afterCommit();
        $queue = config('inbox.queue', 'notify');
        $this->onQueue(is_string($queue) ? $queue : 'notify');
    }

    public function handle(InboxChannels $channels, IngestInboundMessage $ingest, ApplyDeliveryStatus $statuses): void
    {
        $receipt = InboxWebhookReceipt::query()->find($this->receiptId);
        if ($receipt === null || $receipt->processed_at !== null) {
            return;
        }

        $receiver = $channels->receiver($receipt->channel);
        $events = $receiver === null ? [] : $receiver->parse($receipt->payload);

        foreach ($events as $event) {
            match (true) {
                $event instanceof InboundMessage => $ingest->handle($receipt->channel, $event),
                $event instanceof StatusUpdate => $statuses->handle($receipt->channel, $event),
                default => $statuses->watermark($receipt->channel, $event),
            };
        }

        $receipt->events_count = count($events);
        $receipt->processed_at = now();
        $receipt->save();
    }
}
