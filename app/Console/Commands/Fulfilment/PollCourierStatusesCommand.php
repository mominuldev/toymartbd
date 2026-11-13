<?php

declare(strict_types=1);

namespace App\Console\Commands\Fulfilment;

use App\Domain\Fulfilment\Actions\RefreshShipmentStatus;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Jobs\PollShipmentStatuses;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\CourierCircuitBreaker;
use App\Domain\Fulfilment\Services\CourierRegistry;
use Illuminate\Console\Command;

/**
 * The reconciliation poll (P6), scheduled every 15 minutes. Webhooks can be lost, delayed
 * or never configured, so every shipment still with a courier — submitted, booked, picked
 * up, in transit, out for delivery, on hold or partially delivered — that has had no update
 * for `couriers.status_poll.stale_minutes` (and was not polled within that window) is
 * re-queried. Terminal shipments (delivered, returned, cancelled) and ones no courier holds
 * (pending, failed) are never polled. Work is queued per provider in chunks on `courier`;
 * a provider whose circuit is open is skipped this run. `--sync` runs the chunks inline.
 */
final class PollCourierStatusesCommand extends Command
{
    /** Statuses still in a courier's hands. */
    public const array POLLED = [
        ShipmentStatus::Submitted, ShipmentStatus::Booked, ShipmentStatus::PickedUp, ShipmentStatus::InTransit,
        ShipmentStatus::OutForDelivery, ShipmentStatus::OnHold, ShipmentStatus::PartiallyDelivered,
    ];

    protected $signature = 'toymart:couriers:poll {--sync : Run the status checks now instead of queueing them}';

    protected $description = 'Re-query the courier for every in-flight shipment with no recent update';

    public function handle(CourierRegistry $registry, CourierCircuitBreaker $breaker, RefreshShipmentStatus $refresh): int
    {
        $stale = max(1, (int) config('couriers.status_poll.stale_minutes', 60));
        $max = max(1, (int) config('couriers.status_poll.max_per_run', 300));
        $chunk = max(1, (int) config('couriers.status_poll.chunk', 25));

        $providers = CourierProvider::query()->pluck('code', 'id');
        $candidates = Shipment::query()
            ->whereIn('status', array_map(static fn (ShipmentStatus $s): string => $s->value, self::POLLED))
            ->where('updated_at', '<', now()->subMinutes($stale))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($max * 2)
            ->get(['id', 'provider_id']);

        $queued = 0;
        $skipped = [];
        foreach ($candidates->groupBy('provider_id') as $providerId => $shipments) {
            $code = (string) ($providers[$providerId] ?? '');
            if ($code === '' || ! $registry->supports($code) || $breaker->isOpen($code)) {
                $skipped[] = $code === '' ? "#{$providerId}" : $code;

                continue;
            }

            $ids = $shipments->pluck('id')
                ->reject(static fn (int $id): bool => RefreshShipmentStatus::polledRecently($id))
                ->take(max(0, $max - $queued))
                ->values()->all();

            foreach (array_chunk($ids, $chunk) as $ids) {
                /** @var list<int> $ids */
                $this->option('sync') ? (new PollShipmentStatuses($ids))->handle($refresh) : PollShipmentStatuses::dispatch($ids);
                $queued += count($ids);
            }
        }

        $this->info("Courier status poll: {$queued} shipment(s) ".($this->option('sync') ? 'checked' : 'queued').'.'.($skipped === [] ? '' : ' Skipped (no driver or circuit open): '.implode(', ', array_unique($skipped)).'.'));

        return self::SUCCESS;
    }
}
