<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Shipment;

use App\Domain\Fulfilment\Models\Shipment;
use App\Http\Requests\ApiFormRequest;

/** `POST /shipments/{shipment}/retry` — needs orders.update + shipments.book; `Idempotency-Key` required. */
final class RetryShipmentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $shipment = $this->route('shipment');

        return $shipment instanceof Shipment && ($this->user()?->can('retry', $shipment) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
