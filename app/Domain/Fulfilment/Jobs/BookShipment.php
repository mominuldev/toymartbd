<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Jobs;

use App\Domain\Fulfilment\Actions\ApplyCourierStatus;
use App\Domain\Fulfilment\Actions\ClaimShipmentForBooking;
use App\Domain\Fulfilment\Actions\CompleteShipmentBooking;
use App\Domain\Fulfilment\Actions\FailShipment;
use App\Domain\Fulfilment\Actions\MarkBookingUncertain;
use App\Domain\Fulfilment\Contracts\CourierGateway;
use App\Domain\Fulfilment\Contracts\LooksUpByMerchantRef;
use App\Domain\Fulfilment\Contracts\ReportsUncertainBooking;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Exceptions\UnknownCourierProvider;
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
 * Book one shipment with its courier (DATABASE-DESIGN §4.2), on the `courier` queue,
 * OUTSIDE any transaction (rule 4):
 *
 *   1. shipment still `pending`? (else a no-op — the job ran already);
 *   2. provider circuit open → released back to the queue, no call;
 *   3. preflight (active, verified in production — rule 33a, order still shippable, mapped
 *      location — rule 32): a block fails the shipment with its reason;
 *   4. claim: lock the row, re-check `pending`, count the attempt, COMMIT;
 *   5. call `book()` — no lock held;
 *   6. accepted → CompleteShipmentBooking (order shipped, charge); refused → failed;
 *      thrown (transport) → circuit breaker counts it, the job retries with backoff, and
 *      after the last try the shipment fails as `transport`.
 *
 * WithoutOverlapping per shipment plus the locked status re-check mean a job that runs
 * twice never books twice.
 */
final class BookShipment implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(public readonly int $shipmentId, public readonly ?int $userId = null)
    {
        $this->onQueue('courier');
        $this->tries = max(1, (int) config('couriers.booking.tries', 5));
    }

    /**
     * A provider outage keeps releasing the job (circuit open); it gives up after a day.
     * Transport failures are counted against `$tries` by handle() itself.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    /** The job died for good (timeout, crash): the shipment goes to the retry queue. */
    public function failed(?Throwable $e): void
    {
        if (Shipment::query()->find($this->shipmentId)?->status !== ShipmentStatus::Pending) {
            return;
        }
        app(FailShipment::class)->handle($this->shipmentId, Shipment::FAILURE_TRANSPORT, 'Booking job failed: '.mb_substr((string) $e?->getMessage(), 0, 300));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('shipment-booking:'.$this->shipmentId))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(
        CourierRegistry $registry,
        CourierCircuitBreaker $breaker,
        BookingPreflight $preflight,
        ClaimShipmentForBooking $claim,
        CompleteShipmentBooking $complete,
        FailShipment $fail,
    ): void {
        $shipment = Shipment::query()->with('provider')->find($this->shipmentId);
        if ($shipment === null || $shipment->status !== ShipmentStatus::Pending) {
            return;
        }
        $provider = $shipment->provider;

        if ($breaker->isOpen($provider->code)) {
            $this->release(max(1, $breaker->retryAfterSeconds($provider->code)));

            return;
        }

        try {
            $driver = $registry->forProvider($provider);
        } catch (UnknownCourierProvider $e) {
            $fail->handle($shipment->id, Shipment::FAILURE_INACTIVE, $e->getMessage());

            return;
        }

        $outcome = $preflight->check($shipment, $provider, $driver);
        if (! $outcome->isReady() || $outcome->request === null) {
            $fail->handle($shipment->id, (string) $outcome->failureCode, (string) $outcome->message);

            return;
        }

        if ($claim->handle($shipment->id, $outcome->request) === null) {
            return;
        }

        try {
            $result = $driver->book($outcome->request);
        } catch (Throwable $e) {
            if ($e instanceof ReportsUncertainBooking && $e->bookingMayHaveSucceeded()) {
                Log::warning('courier.booking.outcome_unknown', ['shipment_id' => $shipment->id, 'provider' => $provider->code]);
                app(MarkBookingUncertain::class)->handle($shipment->id, $e->getMessage());
                $this->resolveByMerchantRef($driver, $shipment->id, $outcome->request->merchantOrderRef);

                return;
            }
            $breaker->recordFailure($provider->code);
            Log::warning('courier.booking.transport_failed', ['shipment_id' => $shipment->id, 'provider' => $provider->code, 'attempt' => $this->attempts(), 'exception' => $e::class]);

            if ($this->attempts() < $this->tries) {
                $this->release(self::backoffFor($this->attempts()));

                return;
            }
            $fail->handle($shipment->id, Shipment::FAILURE_TRANSPORT, 'The courier could not be reached after '.$this->attempts().' attempts: '.mb_substr($e->getMessage(), 0, 300));

            return;
        }

        $breaker->recordSuccess($provider->code);

        if ($result->accepted && $result->consignmentId !== null) {
            $complete->handle($shipment->id, $result, $this->userId);

            return;
        }

        $fail->handle(
            $shipment->id,
            Shipment::FAILURE_REFUSED,
            $result->error ?? 'The courier did not accept the parcel.',
            $result->providerStatusRaw,
            $result->response,
        );
    }

    /** Ask the provider by our reference right away, when the driver can (Steadfast); else the webhook/poll will. */
    private function resolveByMerchantRef(CourierGateway $driver, int $shipmentId, string $ref): void
    {
        if (! $driver instanceof LooksUpByMerchantRef) {
            return;
        }
        try {
            $status = $driver->statusByMerchantRef($ref);
        } catch (Throwable) {
            return;
        }
        $shipment = Shipment::query()->find($shipmentId);
        if ($status !== null && $status->consignmentId !== null && $shipment !== null) {
            app(ApplyCourierStatus::class)->handle($shipment, $status, 'booking');
        }
    }

    private static function backoffFor(int $attempt): int
    {
        /** @var list<int> $steps */
        $steps = (array) config('couriers.booking.backoff_seconds', [30, 120, 300, 900]);

        return (int) ($steps[$attempt - 1] ?? end($steps) ?: 60);
    }
}
