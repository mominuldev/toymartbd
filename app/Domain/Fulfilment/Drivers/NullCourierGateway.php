<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Drivers;

use App\Domain\Fulfilment\Contracts\CourierGateway;
use App\Domain\Fulfilment\DTOs\BookingResult;
use App\Domain\Fulfilment\DTOs\BulkBookingResult;
use App\Domain\Fulfilment\DTOs\CourierQuote;
use App\Domain\Fulfilment\DTOs\CourierStatusResult;
use App\Domain\Fulfilment\DTOs\CourierWebhookEvent;
use App\Domain\Fulfilment\DTOs\ShipmentRequest;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Support\Money;
use Throwable;

/**
 * The fixture courier for CI and local work (rule 33a: no merchant account exists, so
 * the fulfilment flow is tested against this plus fixtures). It makes no network call.
 * NEVER registered for a real provider in production — tests install it with
 * `CourierRegistry::register($code, new NullCourierGateway($code))` (see
 * tests/Support/CourierFixtures.php).
 *
 * Behaviour is scripted per instance: queue booking answers or exceptions, set the status
 * a consignment reports, and read back every call for assertions. Unscripted calls
 * accept with deterministic ids (`NULL-{shipmentId}`). The shapes are invented, not any
 * provider's.
 *
 * Webhook fixture: header `X-Null-Signature` must equal the secret given to the
 * constructor; body `{"consignment_id": "...", "status": "...", "event_id": "..."}`.
 */
final class NullCourierGateway implements CourierGateway
{
    /** @var list<BookingResult|Throwable> */
    private array $bookingScript = [];

    /** @var list<BulkBookingResult|Throwable> */
    private array $bulkScript = [];

    /** @var array<string, CourierStatusResult|Throwable> */
    private array $statuses = [];

    /** @var list<ShipmentRequest> every `book()` call, for assertions */
    public array $booked = [];

    /** @var list<list<ShipmentRequest>> every `bookMany()` call */
    public array $bulkBooked = [];

    /** @var list<string> every `status()` call */
    public array $statusCalls = [];

    public function __construct(
        private readonly string $code = 'null',
        private readonly bool $requiresLocationMapping = false,
        private readonly bool $supportsBulk = true,
        private readonly string $webhookSecret = 'null-secret',
        private readonly string $charge = '70.00',
    ) {}

    /** Queue the next `book()` answer(s), consumed in order. */
    public function willBook(BookingResult|Throwable ...$results): self
    {
        array_push($this->bookingScript, ...$results);

        return $this;
    }

    public function willBookMany(BulkBookingResult|Throwable ...$results): self
    {
        array_push($this->bulkScript, ...$results);

        return $this;
    }

    public function willReportStatus(string $consignmentId, CourierStatusResult|Throwable $result): self
    {
        $this->statuses[$consignmentId] = $result;

        return $this;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function requiresLocationMapping(): bool
    {
        return $this->requiresLocationMapping;
    }

    public function supportsBulk(): bool
    {
        return $this->supportsBulk;
    }

    public function quote(ShipmentRequest $r): CourierQuote
    {
        return new CourierQuote(Money::of($this->charge), $r->codAmount->percentage(1), 2);
    }

    public function book(ShipmentRequest $r): BookingResult
    {
        $this->booked[] = $r;
        $next = array_shift($this->bookingScript);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next ?? BookingResult::accepted(
            'NULL-'.$r->shipmentId,
            'NT'.str_pad((string) $r->shipmentId, 8, '0', STR_PAD_LEFT),
            Money::of($this->charge),
            'in_review',
        );
    }

    public function bookMany(array $rs): BulkBookingResult
    {
        $this->bulkBooked[] = $rs;
        $next = array_shift($this->bulkScript);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next ?? new BulkBookingResult(true, refs: array_map(static fn (ShipmentRequest $r): string => $r->merchantOrderRef, $rs));
    }

    public function status(string $consignmentId): CourierStatusResult
    {
        $this->statusCalls[] = $consignmentId;
        $result = $this->statuses[$consignmentId] ?? new CourierStatusResult(ShipmentStatus::Booked, 'in_review');

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        $given = $headers['x-null-signature'] ?? $headers['X-Null-Signature'] ?? null;
        $given = is_array($given) ? ($given[0] ?? null) : $given;

        return is_string($given) && hash_equals($this->webhookSecret, $given);
    }

    public function parseWebhook(string $rawBody): ?CourierWebhookEvent
    {
        $data = json_decode($rawBody, true);

        if (! is_array($data) || ! is_string($data['consignment_id'] ?? null) || ! is_string($data['status'] ?? null)) {
            return null;
        }

        return new CourierWebhookEvent(
            $data['consignment_id'],
            is_string($data['merchant_order_ref'] ?? null) ? $data['merchant_order_ref'] : null,
            $data['status'],
            is_string($data['event_id'] ?? null) ? $data['event_id'] : null,
        );
    }

    /**
     * The raw statuses this fixture driver emits, normalised. Real drivers enumerate EVERY
     * documented provider status here (brief: each driver ships a statusMap()).
     *
     * @return array<string, ShipmentStatus>
     */
    public static function statusMap(): array
    {
        return [
            'in_review' => ShipmentStatus::Booked,
            'picked_up' => ShipmentStatus::PickedUp,
            'in_transit' => ShipmentStatus::InTransit,
            'out_for_delivery' => ShipmentStatus::OutForDelivery,
            'hold' => ShipmentStatus::OnHold,
            'delivered' => ShipmentStatus::Delivered,
            'partial_delivered' => ShipmentStatus::PartiallyDelivered,
            'returned' => ShipmentStatus::Returned,
            'cancelled' => ShipmentStatus::Cancelled,
        ];
    }
}
