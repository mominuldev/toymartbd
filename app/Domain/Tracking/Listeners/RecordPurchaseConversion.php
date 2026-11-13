<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Listeners;

use App\Domain\Ordering\Enums\OrderSource;
use App\Domain\Ordering\Events\OrderDelivered;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Models\Order;
use App\Domain\Tracking\Actions\RecordTrackingEvent;
use App\Domain\Tracking\Enums\PurchaseTrigger;
use App\Domain\Tracking\Jobs\DispatchTrackingEvent;
use App\Domain\Tracking\Models\OrderTrackingContext;
use App\Domain\Tracking\Services\OrderConversions;
use App\Domain\Tracking\Services\TrackingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The server-side `purchase` (PRD K-02), on EXACTLY ONE of OrderPlaced / OrderDelivered —
 * whichever `TrackingSettings::purchaseTrigger()` names. The other event is ignored, and the
 * deterministic event_id + the idempotent ingest key mean even a trigger switched between
 * placement and delivery records one purchase per order, never two.
 *
 * Queued on `tracking`, after commit. On OrderPlaced it is DELAYED
 * (`tracking.purchase_dispatch_delay_seconds`): the checkout controller writes the order's
 * browser context just after PlaceOrder commits, and the delay lets that land first. If a
 * web order's context is still missing on a real queue, the listener releases itself once
 * more (up to 2 minutes after placement) before falling back to server context.
 *
 * Staff-entered orders (admin, phone, Facebook, WhatsApp) never have a browser context: they
 * are sent with server context only, under the consent DispatchPolicy::serverConsent()
 * allows (`notice` → sent, `opt_in` → skipped_consent).
 */
final class RecordPurchaseConversion implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        private readonly TrackingSettings $settings,
        private readonly OrderConversions $conversions,
        private readonly RecordTrackingEvent $record,
    ) {}

    public function viaQueue(): string
    {
        return (string) config('tracking.queue', 'tracking');
    }

    public function withDelay(OrderPlaced|OrderDelivered $event): int
    {
        return $event instanceof OrderPlaced ? (int) config('tracking.purchase_dispatch_delay_seconds', 20) : 0;
    }

    public function shouldQueue(OrderPlaced|OrderDelivered $event): bool
    {
        return $this->fires($event);
    }

    public function handle(OrderPlaced|OrderDelivered $event): void
    {
        if (! $this->fires($event)) {
            return;
        }
        $order = Order::query()->find($event->orderId);
        if ($order === null) {
            return;
        }

        if ($this->shouldWaitForContext($event, $order)) {
            $this->release(30);

            return;
        }

        $occurredAt = CarbonImmutable::instance(
            ($event instanceof OrderDelivered ? $order->delivered_at : $order->placed_at) ?? now(),
        );
        ['event' => $row, 'created' => $created] = $this->record->handle($this->conversions->purchase($order, $occurredAt));
        if ($created) {
            DispatchTrackingEvent::dispatch($row->id);
        }
    }

    private function fires(OrderPlaced|OrderDelivered $event): bool
    {
        return $this->settings->purchaseTrigger() === ($event instanceof OrderPlaced ? PurchaseTrigger::OrderPlaced : PurchaseTrigger::OrderDelivered);
    }

    /** Race guard: a web order placed moments ago whose checkout context has not landed yet. */
    private function shouldWaitForContext(OrderPlaced|OrderDelivered $event, Order $order): bool
    {
        if (! $event instanceof OrderPlaced || $order->source !== OrderSource::Web) {
            return false;
        }
        if ($this->job === null || $this->job->getConnectionName() === 'sync' || $this->attempts() >= $this->tries) {
            return false;
        }
        if ($order->placed_at === null || $order->placed_at->lt(now()->subMinutes(2))) {
            return false;
        }

        return ! OrderTrackingContext::query()->where('order_id', $order->id)->exists();
    }
}
