<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Shipment;

use App\Domain\Fulfilment\Actions\CancelShipment;
use App\Domain\Fulfilment\Models\Shipment;
use App\Http\Requests\ApiFormRequest;

/** `POST /shipments/{shipment}/cancel` — only pending or failed shipments; needs shipments.cancel. */
final class CancelShipmentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $shipment = $this->route('shipment');

        return $shipment instanceof Shipment && ($this->user()?->can('cancel', $shipment) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Why, kept on the shipment. */
            'reason' => ['nullable', 'string', 'max:'.CancelShipment::REASON_MAX],
        ];
    }

    public function reason(): ?string
    {
        $v = $this->input('reason');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
