<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Jobs;

use App\Domain\Fulfilment\Actions\RefreshShipmentStatus;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\CourierWebhookReceipt;
use App\Domain\Fulfilment\Models\Shipment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * A verified courier webhook, processed off the request on the `courier` queue: find the
 * shipment it names, RE-QUERY the provider and apply the provider's answer (rule 24c). The
 * receipt is marked processed only once the outcome is final; a provider that cannot be
 * reached leaves it open and the job retries with backoff (the poll catches up after that).
 */
final class ProcessCourierWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $receiptId)
    {
        $this->onQueue('courier');
    }

    public function handle(RefreshShipmentStatus $refresh): void
    {
        $receipt = CourierWebhookReceipt::query()->find($this->receiptId);
        if ($receipt === null || $receipt->processed_at !== null) {
            return;
        }

        $consignment = is_string($receipt->payload['consignment_id'] ?? null) ? $receipt->payload['consignment_id'] : null;
        $ref = is_string($receipt->payload['merchant_order_ref'] ?? null) ? $receipt->payload['merchant_order_ref'] : null;
        $providerId = CourierProvider::query()->where('code', $receipt->courierCode())->value('id');

        $shipment = $providerId === null ? null : self::shipment((int) $providerId, $consignment, $ref);
        if ($shipment === null) {
            self::finish($receipt, 'unknown_shipment');

            return;
        }

        $outcome = $refresh->handle($shipment->id, 'webhook', $shipment->consignment_id === null ? $consignment : null);

        if (in_array($outcome, ['deferred', 'circuit_open'], true)) {
            $receipt->forceFill(['result' => $outcome])->save();
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 900);
            }

            return;
        }

        self::finish($receipt, $outcome);
    }

    private static function shipment(int $providerId, ?string $consignment, ?string $ref): ?Shipment
    {
        if ($consignment !== null) {
            $byConsignment = Shipment::query()->where('provider_id', $providerId)->where('consignment_id', $consignment)->orderByDesc('id')->first();
            if ($byConsignment !== null) {
                return $byConsignment;
            }
        }

        // A bulk-submitted parcel has no consignment id yet: it is known by our merchant ref,
        // the shipment number (docs/api/shipments.md).
        return $ref === null ? null : Shipment::query()->where('provider_id', $providerId)->where('number', $ref)->first();
    }

    private static function finish(CourierWebhookReceipt $receipt, string $result): void
    {
        $receipt->forceFill(['processed_at' => now(), 'result' => Str::limit($result, 32, '')])->save();
    }
}
