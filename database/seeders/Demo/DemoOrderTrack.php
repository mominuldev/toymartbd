<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Ordering\Models\Order;

/**
 * One planned demo order while its steps run: the plan, and what the steps created so
 * far (the order, its courier shipment, its open return). A step whose order was never
 * placed (no stock headroom, a coupon that no longer applies twice) does nothing.
 */
final class DemoOrderTrack
{
    public ?Order $order = null;

    public ?int $shipmentId = null;

    public ?string $consignment = null;

    public ?int $returnId = null;

    /**
     * @param  array{who: string, channel: string, target: string, size: string, via: string, coupon: string|null, utm: string|null, note: string|null}  $plan
     */
    public function __construct(public readonly array $plan) {}

    /** The order as it is now, or null when it was never placed. */
    public function fresh(): ?Order
    {
        return $this->order = $this->order?->refresh();
    }
}
