<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Jobs;

use App\Domain\Fulfilment\Actions\ClaimShipmentForBooking;
use App\Domain\Fulfilment\Actions\FailShipment;
use App\Domain\Fulfilment\Actions\MarkBookingUncertain;
use App\Domain\Fulfilment\Actions\MarkShipmentsSubmitted;
use App\Domain\Fulfilment\Contracts\ReportsUncertainBooking;
use App\Domain\Fulfilment\DTOs\ShipmentRequest;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\BookingPreflight;
use App\Domain\Fulfilment\Services\CourierCircuitBreaker;
use App\Domain\Fulfilment\Services\CourierRegistry;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bulk booking (rule 24d), on `courier`, outside any transaction. Per provider: preflight
 * every pending shipment (a blocked one fails on its own, with its reason), claim the rest,
 * then ONE `bookMany()` call. Accepted → each shipment `submitted` with no consignment id
 * (neither provider returns ids from bulk); the order is untouched until a webhook/poll
 * confirms each parcel. Refused → each fails as `provider_refused`. Thrown → retried with
 * backoff (circuit breaker counted); after the last try each fails as `transport`.
 *
 * A driver without bulk gets one BookShipment job per shipment instead.
 */
final class BookShipmentsBulk implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @param  list<int>  $shipmentIds */
    public function __construct(public readonly array $shipmentIds, public readonly ?int $userId = null)
    {
        $this->onQueue('courier');
        $this->tries = max(1, (int) config('couriers.booking.tries', 5));
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    /** The job died for good: every shipment it still held pending goes to the retry queue. */
    public function failed(?Throwable $e): void
    {
        $pending = Shipment::query()->whereIn('id', $this->shipmentIds)->where('status', ShipmentStatus::Pending->value)->pluck('id');
        foreach ($pending as $id) {
            app(FailShipment::class)->handle((int) $id, Shipment::FAILURE_TRANSPORT, 'Bulk booking job failed: '.mb_substr((string) $e?->getMessage(), 0, 300));
        }
    }

    /** @return list<object> */
    public function middleware(): array
    {
        $ids = $this->shipmentIds;
        sort($ids);

        return [(new WithoutOverlapping('shipment-bulk:'.md5(implode(',', $ids))))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(
        CourierRegistry $registry,
        CourierCircuitBreaker $breaker,
        BookingPreflight $preflight,
        ClaimShipmentForBooking $claim,
        MarkShipmentsSubmitted $submitted,
        FailShipment $fail,
    ): void {
        $pending = Shipment::query()->with('provider')->whereIn('id', $this->shipmentIds)
            ->where('status', ShipmentStatus::Pending->value)->orderBy('id')->get();
        $retry = false;

        foreach ($pending->groupBy('provider_id') as $group) {
            $provider = $group->first()->provider;
            if ($breaker->isOpen($provider->code)) {
                $retry = true;

                continue;
            }
            $driver = $registry->forProvider($provider);

            if (! $driver->supportsBulk() || ! $provider->supports_bulk) {
                foreach ($group as $shipment) {
                    BookShipment::dispatch($shipment->id, $this->userId);
                }

                continue;
            }

            /** @var array<int, ShipmentRequest> $requests */
            $requests = [];
            foreach ($group as $shipment) {
                $outcome = $preflight->check($shipment, $provider, $driver);
                if (! $outcome->isReady() || $outcome->request === null) {
                    $fail->handle($shipment->id, (string) $outcome->failureCode, (string) $outcome->message);

                    continue;
                }
                if ($claim->handle($shipment->id, $outcome->request) !== null) {
                    $requests[$shipment->id] = $outcome->request;
                }
            }
            if ($requests === []) {
                continue;
            }

            try {
                $result = $driver->bookMany(array_values($requests));
            } catch (Throwable $e) {
                if ($e instanceof ReportsUncertainBooking && $e->bookingMayHaveSucceeded()) {
                    Log::warning('courier.bulk_booking.outcome_unknown', ['provider' => $provider->code, 'count' => count($requests)]);
                    foreach (array_keys($requests) as $id) {
                        app(MarkBookingUncertain::class)->handle($id, $e->getMessage());
                    }

                    continue;
                }
                $breaker->recordFailure($provider->code);
                Log::warning('courier.bulk_booking.transport_failed', ['provider' => $provider->code, 'count' => count($requests), 'attempt' => $this->attempts(), 'exception' => $e::class]);
                if ($this->attempts() < $this->tries) {
                    $retry = true;

                    continue;
                }
                foreach (array_keys($requests) as $id) {
                    $fail->handle($id, Shipment::FAILURE_TRANSPORT, 'The courier could not be reached for the bulk booking: '.mb_substr($e->getMessage(), 0, 300));
                }

                continue;
            }

            $breaker->recordSuccess($provider->code);
            if ($result->submitted) {
                $submitted->handle(array_keys($requests));

                continue;
            }
            foreach (array_keys($requests) as $id) {
                $fail->handle($id, Shipment::FAILURE_REFUSED, $result->error ?? 'The courier refused the bulk booking.');
            }
        }

        if ($retry) {
            $this->release(60);
        }
    }
}
