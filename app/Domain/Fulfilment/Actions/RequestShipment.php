<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Contracts\ShippableOrders;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Exceptions\CourierUnavailable;
use App\Domain\Fulfilment\Exceptions\ShipmentNotRequestable;
use App\Domain\Fulfilment\Jobs\BookShipment;
use App\Domain\Fulfilment\Models\CourierLocation;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\CourierRegistry;
use App\Domain\Fulfilment\Services\ShipmentNumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Confirm order → dispatch, first half (DATABASE-DESIGN §4.2). In ONE transaction:
 *
 *   lock the order (Ordering's ShippableOrders seam) → it must be confirmed, processing or
 *   ready_to_ship → no open shipment for it (only a failed or cancelled one frees it) →
 *   draw a shipment number → INSERT shipments (status pending, COD = the order's due
 *   total, weight from the variants unless overridden).
 *
 * COMMIT, then (unless the caller batches them) BookShipment is queued on `courier` — the
 * courier is never called while a lock is held (rule 4).
 */
final readonly class RequestShipment
{
    public const int NOTE_MAX = 500;

    public function __construct(
        private ShippableOrders $orders,
        private ShipmentNumberGenerator $numbers,
        private CourierRegistry $registry,
    ) {}

    /**
     * @throws ShipmentNotRequestable (409)
     * @throws CourierUnavailable (422)
     */
    public function handle(
        int $orderId,
        ?string $providerCode = null,
        ?int $courierLocationId = null,
        ?int $weightGrams = null,
        ?string $note = null,
        ?int $userId = null,
        bool $dispatch = true,
    ): Shipment {
        $provider = $this->provider($providerCode);
        if ($courierLocationId !== null && ! CourierLocation::query()->where('provider_id', $provider->id)->whereKey($courierLocationId)->exists()) {
            throw CourierUnavailable::locationNotForProvider();
        }
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, self::NOTE_MAX) : null;

        $shipment = DB::transaction(function () use ($orderId, $provider, $courierLocationId, $weightGrams, $note, $userId): Shipment {
            $order = $this->orders->lockForShipment($orderId) ?? throw ShipmentNotRequestable::orderMissing($orderId);
            if (! $order->canShip) {
                throw ShipmentNotRequestable::orderNotShippable($order->number, $order->status);
            }

            $open = Shipment::query()->open()->where('order_id', $order->id)->first();
            if ($open !== null) {
                throw ShipmentNotRequestable::openShipmentExists($order->number, $open->number);
            }

            return Shipment::query()->create([
                'order_id' => $order->id,
                'provider_id' => $provider->id,
                'number' => $this->numbers->next(),
                'status' => ShipmentStatus::Pending,
                'courier_location_id' => $courierLocationId,
                'weight_grams' => $weightGrams ?? $order->weightGrams,
                'item_count' => $order->itemCount,
                'cod_amount' => $order->codAmount,
                'declared_value' => $order->declaredValue,
                'note' => $note,
                'requested_by' => $userId,
            ]);
        });

        if ($dispatch) {
            BookShipment::dispatch($shipment->id, $userId);
        }

        return $shipment->load('provider');
    }

    /** The named provider, or the active default (the one storefront quotes use). */
    public function provider(?string $code): CourierProvider
    {
        $provider = $code === null
            ? (CourierProvider::defaultForQuotes() ?? throw CourierUnavailable::noDefault())
            : (CourierProvider::query()->where('code', $code)->first() ?? throw CourierUnavailable::unknown($code));

        if (! $provider->is_active) {
            throw CourierUnavailable::inactive($provider->code);
        }
        if (! $this->registry->supports($provider->code)) {
            throw CourierUnavailable::unknown($provider->code);
        }

        return $provider;
    }
}
