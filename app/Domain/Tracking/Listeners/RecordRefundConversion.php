<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Listeners;

use App\Domain\Ordering\Events\RefundPaid;
use App\Domain\Ordering\Models\Order;
use App\Domain\Tracking\Actions\RecordTrackingEvent;
use App\Domain\Tracking\Jobs\DispatchTrackingEvent;
use App\Domain\Tracking\Services\OrderConversions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The server-side `refund` (PRD K-02) when a refund is actually paid out. GA4 only — Meta has
 * no refund event, so DispatchPolicy marks Meta `not_applicable`. event_id = UUIDv5 of
 * `refund:{refund_id}`, so a re-delivered RefundPaid records nothing new.
 */
final class RecordRefundConversion implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly OrderConversions $conversions,
        private readonly RecordTrackingEvent $record,
    ) {}

    public function viaQueue(): string
    {
        return (string) config('tracking.queue', 'tracking');
    }

    public function handle(RefundPaid $event): void
    {
        $order = Order::query()->find($event->orderId);
        if ($order === null) {
            return;
        }
        ['event' => $row, 'created' => $created] = $this->record->handle(
            $this->conversions->refund($order, $event->refundId, $event->amount, CarbonImmutable::now()),
        );
        if ($created) {
            DispatchTrackingEvent::dispatch($row->id);
        }
    }
}
