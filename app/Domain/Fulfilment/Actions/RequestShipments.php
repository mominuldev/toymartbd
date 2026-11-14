<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\DTOs\ShipmentRequestOutcome;
use App\Domain\Fulfilment\Exceptions\ShipmentNotRequestable;
use App\Domain\Fulfilment\Jobs\BookShipment;
use App\Domain\Fulfilment\Jobs\BookShipmentsBulk;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Services\CourierRegistry;

/**
 * Bulk "send to courier" from the order board. Each order is requested on its own
 * (RequestShipment, its own transaction), so one unshippable order never blocks the rest;
 * the caller gets one outcome per id. The shipments that were created then go to the
 * courier together: one BookShipmentsBulk job (→ `submitted`, no consignment ids, rule
 * 24d) when the driver supports bulk, else one BookShipment job each.
 */
final readonly class RequestShipments
{
    public function __construct(
        private RequestShipment $request,
        private CourierRegistry $registry,
    ) {}

    /**
     * @param  list<int>  $orderIds
     * @return list<ShipmentRequestOutcome>
     */
    public function handle(array $orderIds, ?string $providerCode = null, ?int $userId = null): array
    {
        $provider = $this->request->provider($providerCode);

        $outcomes = [];
        $created = [];
        try {
            foreach (array_values(array_unique($orderIds)) as $orderId) {
                try {
                    $shipment = $this->request->handle($orderId, $provider->code, userId: $userId, dispatch: false);
                    $created[] = $shipment->id;
                    $outcomes[] = new ShipmentRequestOutcome($orderId, ShipmentRequestOutcome::REQUESTED, $shipment);
                } catch (ShipmentNotRequestable $e) {
                    $outcomes[] = new ShipmentRequestOutcome(
                        $orderId,
                        $e->orderMissing ? ShipmentRequestOutcome::NOT_FOUND : ShipmentRequestOutcome::CONFLICT,
                        message: $e->getMessage(),
                    );
                }
            }
        } finally {
            // Shipments already committed are always handed to the courier, even when a later
            // order throws something unexpected — otherwise they would sit `pending` with no
            // booking job (P9, LOAD-AND-CONCURRENCY F-3).
            $this->dispatch($provider, $created, $userId);
        }

        return $outcomes;
    }

    /** @param  list<int>  $created */
    private function dispatch(CourierProvider $provider, array $created, ?int $userId): void
    {
        if ($created === []) {
            return;
        }
        if ($this->registry->forProvider($provider)->supportsBulk() && $provider->supports_bulk) {
            BookShipmentsBulk::dispatch($created, $userId);

            return;
        }
        foreach ($created as $id) {
            BookShipment::dispatch($id, $userId);
        }
    }
}
