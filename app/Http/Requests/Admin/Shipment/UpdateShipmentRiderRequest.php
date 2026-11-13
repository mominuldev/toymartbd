<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Shipment;

use App\Domain\Fulfilment\Actions\RecordShipmentRider;
use App\Domain\Fulfilment\Models\Shipment;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Storefront\Account\PhoneRule;
use App\Support\PhoneNumber;

/**
 * `PATCH /shipments/{shipment}/rider` — the delivery rider's name and phone, typed in when the
 * rider calls (neither courier's API returns them). Both null clears the rider. The phone is
 * a Bangladeshi mobile, 013–019 only (rule 30), stored E.164. Needs shipments.book.
 */
final class UpdateShipmentRiderRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $shipment = $this->route('shipment');

        return $shipment instanceof Shipment && ($this->user()?->can('recordRider', $shipment) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The rider's name as they gave it. */
            'rider_name' => ['nullable', 'string', 'max:'.RecordShipmentRider::NAME_MAX],
            /** The rider's mobile: `01XXXXXXXXX`, `8801XXXXXXXXX` or `+8801XXXXXXXXX`, 013–019 only. */
            'rider_phone' => ['nullable', 'string', 'max:32', PhoneRule::make()],
        ];
    }

    public function riderName(): ?string
    {
        $v = $this->validated('rider_name');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    public function riderPhone(): ?PhoneNumber
    {
        $v = $this->validated('rider_phone');

        return is_string($v) ? PhoneNumber::tryParse($v) : null;
    }
}
