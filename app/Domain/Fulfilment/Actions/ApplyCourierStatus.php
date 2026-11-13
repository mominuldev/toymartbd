<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\DTOs\CourierStatusResult;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Events\ShipmentDelivered;
use App\Domain\Fulfilment\Events\ShipmentPartiallyDelivered;
use App\Domain\Fulfilment\Events\ShipmentReturned;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\ShipmentBookingRecorder;
use App\Domain\Fulfilment\Services\ShipmentTransitions;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Apply an AUTHORITATIVE courier status — one re-queried from the provider (`status()`),
 * never a webhook body (rules 19, 24c) — to a shipment. The entry point for webhook and
 * poll processing. In ONE transaction, with the shipment row locked:
 *
 *   1. A shipment the courier holds but we never dispatched (a bulk `submitted` parcel, or a
 *      `completion_failed` one) is completed first — consignment id recorded, order marked
 *      shipped, `courier_charge` — exactly as a single booking (ShipmentBookingRecorder).
 *   2. Same status → only `provider_status_raw` / `cod_collected` refreshed.
 *   3. A move the ShipmentStatus table refuses (a late or replayed event, e.g. "in transit"
 *      after "delivered") is ignored and logged — never forced.
 *   4. Otherwise the status moves, and:
 *      - delivered → `cod_fee` (provider's cod_fee_percent of the amount collected) and
 *        ShipmentDelivered: the order is delivered through MarkOrderDelivered (revenue
 *        recognised on OrderDelivered, as for manual delivery) and Finance writes
 *        `courier_cod_fee`;
 *      - returned → `return_charge` (the provider's rate card) and ShipmentReturned: courier
 *        return on the order (`courier_return_in`, order `returned`) and Finance writes
 *        `courier_return_charge`, reversing revenue only if it had been recognised;
 *      - partially_delivered → ShipmentPartiallyDelivered: flagged on the order for staff;
 *      - out_for_delivery → `delivery_attempts` + 1.
 */
final readonly class ApplyCourierStatus
{
    public function __construct(
        private ShipmentTransitions $transitions,
        private ShipmentBookingRecorder $recorder,
    ) {}

    public function handle(Shipment $shipment, CourierStatusResult $result, string $source = 'poll'): Shipment
    {
        return DB::transaction(function () use ($shipment, $result, $source): Shipment {
            /** @var Shipment $locked */
            $locked = Shipment::query()->with('provider')->lockForUpdate()->findOrFail($shipment->id);
            $to = $result->status;

            if ($this->needsCompletion($locked, $to) && ! $this->complete($locked, $result, $source)) {
                return $locked->refresh();
            }

            $refresh = array_filter([
                'provider_status_raw' => mb_substr($result->rawStatus, 0, 64),
                'cod_collected' => $result->codCollected,
                'consignment_id' => $locked->consignment_id === null ? $result->consignmentId : null,
                'tracking_code' => $locked->tracking_code === null ? $result->trackingCode : null,
            ], static fn (mixed $v): bool => $v !== null);

            if ($to === $locked->status) {
                $locked->forceFill($refresh)->save();

                return $locked;
            }

            if (! $locked->status->canTransitionTo($to)) {
                Log::info('courier.status.ignored', ['shipment_id' => $locked->id, 'from' => $locked->status->value, 'to' => $to->value, 'raw' => $result->rawStatus, 'source' => $source]);

                return $locked;
            }

            $occurredAt = $result->occurredAt ?? now()->toImmutable();
            $attributes = $refresh;
            if (($column = $to->timestampColumn()) !== null) {
                $attributes[$column] = $occurredAt;
            }

            if ($to === ShipmentStatus::OutForDelivery) {
                $attributes['delivery_attempts'] = min(255, $locked->delivery_attempts + 1);
            }
            if ($to === ShipmentStatus::Delivered) {
                $collected = $result->codCollected ?? $locked->cod_amount;
                $fee = $collected->percentage($locked->provider->cod_fee_percent);
                $attributes['cod_collected'] = $collected;
                $attributes['cod_fee'] = $fee;
                $attributes['total_cost'] = ($locked->charge ?? Money::zero())->plus($fee);
            }
            if ($to === ShipmentStatus::Returned) {
                $returnCharge = $locked->provider->return_charge;
                $attributes['return_charge'] = $returnCharge;
                $attributes['total_cost'] = ($locked->charge ?? Money::zero())->plus($locked->cod_fee ?? Money::zero())->plus($returnCharge);
            }

            $this->transitions->move($locked, $to, $source, $attributes);

            $at = $occurredAt->toIso8601String();
            match ($to) {
                ShipmentStatus::Delivered => ShipmentDelivered::dispatch(
                    $locked->id, $locked->order_id, $locked->number, $locked->provider->code,
                    ($locked->cod_fee ?? Money::zero())->toDecimal(), $locked->cod_collected?->toDecimal(), $at,
                ),
                ShipmentStatus::Returned => ShipmentReturned::dispatch(
                    $locked->id, $locked->order_id, $locked->number, $locked->provider->code,
                    ($locked->return_charge ?? Money::zero())->toDecimal(), $at,
                ),
                ShipmentStatus::PartiallyDelivered => ShipmentPartiallyDelivered::dispatch(
                    $locked->id, $locked->order_id, $locked->number, $locked->provider->code,
                    $locked->cod_collected?->toDecimal(), $at,
                ),
                default => null,
            };

            return $locked;
        });
    }

    /** The courier has the parcel (or it is past that) but our order was never dispatched for it. */
    private function needsCompletion(Shipment $locked, ShipmentStatus $to): bool
    {
        if ($locked->isDispatched()) {
            return false;
        }
        if (! in_array($locked->status, [ShipmentStatus::Pending, ShipmentStatus::Submitted, ShipmentStatus::Booked], true)) {
            return false;
        }

        return $to->isWithCourier() || in_array($to, [ShipmentStatus::Delivered, ShipmentStatus::PartiallyDelivered, ShipmentStatus::Returned], true);
    }

    /**
     * Complete the booking inside a savepoint; on refusal keep the courier's ids on the
     * shipment, flag it `completion_failed` and stop (the status is applied once completed).
     */
    private function complete(Shipment $locked, CourierStatusResult $result, string $source): bool
    {
        try {
            DB::transaction(fn (): Shipment => $this->recorder->markBooked(
                $locked, $result->consignmentId, $result->trackingCode, null, $result->charge, mb_substr($result->rawStatus, 0, 64), null, null, $source,
            ));

            return true;
        } catch (Throwable $e) {
            Log::error('courier.status.completion_failed', ['shipment_id' => $locked->id, 'exception' => $e::class, 'message' => $e->getMessage()]);
            $locked->refresh();
            $locked->forceFill(array_filter([
                'status' => $locked->status === ShipmentStatus::Booked ? null : ShipmentStatus::Booked,
                'booked_at' => $locked->booked_at === null ? now() : null,
                'consignment_id' => $result->consignmentId ?? $locked->consignment_id,
                'tracking_code' => $result->trackingCode ?? $locked->tracking_code,
                'charge' => $result->charge ?? $locked->charge,
                'provider_status_raw' => mb_substr($result->rawStatus, 0, 64),
            ], static fn (mixed $v): bool => $v !== null) + [
                'failure_code' => Shipment::FAILURE_COMPLETION,
                'failure_reason' => 'The courier holds the parcel, but the order could not be marked shipped.',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            return false;
        }
    }
}
