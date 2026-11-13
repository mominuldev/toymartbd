<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Contracts\LooksUpByMerchantRef;
use App\Domain\Fulfilment\DTOs\CourierStatusResult;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Exceptions\UnknownCourierProvider;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\CourierCircuitBreaker;
use App\Domain\Fulfilment\Services\CourierRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the courier for a shipment's authoritative status and applies THAT answer through
 * ApplyCourierStatus (rules 19, 24c). The single path for both a webhook trigger and the
 * reconciliation poll. The provider call happens here, outside any transaction (rule 4);
 * ApplyCourierStatus opens its own.
 *
 *  - a terminal shipment (delivered, returned, cancelled) or one no courier holds
 *    (pending, failed) is never queried;
 *  - provider circuit open → `circuit_open`, nothing called;
 *  - no consignment id yet (a bulk-`submitted` parcel, rule 24d): resolved by our merchant
 *    ref (the shipment number) when the driver offers {@see LooksUpByMerchantRef} (Steadfast
 *    `status_by_invoice`); else, when a verified webhook named a consignment id, the provider
 *    is asked about THAT id — the courier's answer, not the webhook body, is what is applied;
 *    otherwise `unresolved` (wait for the webhook);
 *  - a thrown call counts towards the circuit breaker and returns `deferred` (retry later).
 *
 * Returns the outcome code: applied | terminal | not_with_courier | circuit_open | unresolved
 * | no_driver | deferred.
 */
final readonly class RefreshShipmentStatus
{
    public function __construct(
        private CourierRegistry $registry,
        private CourierCircuitBreaker $breaker,
        private ApplyCourierStatus $apply,
    ) {}

    public function handle(int $shipmentId, string $source, ?string $consignmentHint = null): string
    {
        $shipment = Shipment::query()->with('provider')->find($shipmentId);
        if ($shipment === null) {
            return 'not_with_courier';
        }
        if ($shipment->status->isTerminal()) {
            return 'terminal';
        }
        if (in_array($shipment->status, [ShipmentStatus::Pending, ShipmentStatus::Failed], true)) {
            return 'not_with_courier';
        }

        $code = $shipment->provider->code;
        try {
            $driver = $this->registry->for($code);
        } catch (UnknownCourierProvider) {
            return 'no_driver';
        }
        if ($this->breaker->isOpen($code)) {
            return 'circuit_open';
        }

        self::markPolled($shipment->id);

        try {
            $result = match (true) {
                $shipment->consignment_id !== null => $driver->status($shipment->consignment_id),
                $driver instanceof LooksUpByMerchantRef => $driver->statusByMerchantRef($shipment->number),
                $consignmentHint !== null => self::withConsignment($driver->status($consignmentHint), $consignmentHint),
                default => null,
            };
        } catch (Throwable $e) {
            $this->breaker->recordFailure($code);
            Log::warning('courier.status.unavailable', ['provider' => $code, 'shipment_id' => $shipment->id, 'error' => $e::class]);

            return 'deferred';
        }
        $this->breaker->recordSuccess($code);

        if ($result === null) {
            return 'unresolved';
        }
        if ($shipment->consignment_id === null && $result->consignmentId === null && $consignmentHint !== null) {
            $result = self::withConsignment($result, $consignmentHint);
        }
        if ($shipment->consignment_id === null && $result->consignmentId === null) {
            return 'unresolved';
        }

        $this->apply->handle($shipment, $result, $source);

        return 'applied';
    }

    /** Whether the poll asked about this shipment within the last `stale_minutes`. */
    public static function polledRecently(int $shipmentId): bool
    {
        return Cache::has(self::pollKey($shipmentId));
    }

    private static function markPolled(int $shipmentId): void
    {
        Cache::put(self::pollKey($shipmentId), true, now()->addMinutes(max(1, (int) config('couriers.status_poll.stale_minutes', 60))));
    }

    private static function pollKey(int $shipmentId): string
    {
        return "courier:polled:{$shipmentId}";
    }

    /** The provider answered for `$consignmentId`, so the shipment's id is that one. */
    private static function withConsignment(CourierStatusResult $r, string $consignmentId): CourierStatusResult
    {
        return new CourierStatusResult($r->status, $r->rawStatus, $r->occurredAt, $r->codCollected, $r->charge, $r->consignmentId ?? $consignmentId, $r->trackingCode, $r->message);
    }
}
