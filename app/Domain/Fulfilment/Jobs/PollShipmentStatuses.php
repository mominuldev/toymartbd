<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Jobs;

use App\Domain\Fulfilment\Actions\RefreshShipmentStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One chunk of the reconciliation poll for one provider, on the `courier` queue. Stops at
 * the first sign the provider is down (circuit open, or a call deferred that opened it) —
 * the next run picks the rest up.
 */
final class PollShipmentStatuses implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  list<int>  $shipmentIds */
    public function __construct(public readonly array $shipmentIds)
    {
        $this->onQueue('courier');
    }

    public function handle(RefreshShipmentStatus $refresh): void
    {
        foreach ($this->shipmentIds as $id) {
            if ($refresh->handle($id, 'poll') === 'circuit_open') {
                return;
            }
        }
    }
}
