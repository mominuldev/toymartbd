<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Shipment;

use App\Domain\Fulfilment\Actions\RequestShipment;
use App\Domain\Fulfilment\Models\Shipment;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /orders/{order}/shipments` — send one order to a courier (P6, DATABASE-DESIGN §4.2).
 * Needs orders.update + shipments.book; `Idempotency-Key` required (rule 17).
 */
final class RequestShipmentRequest extends ApiFormRequest
{
    public const int MAX_WEIGHT_GRAMS = 100000;

    public function authorize(): bool
    {
        return $this->user()?->can('request', Shipment::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Courier provider code (e.g. `steadfast`, `pathao`). Omitted: the active default provider. */
            'provider' => ['nullable', 'string', 'max:32', 'exists:courier_providers,code'],
            /** A mapped `courier_locations` id of that provider — required in effect when the address's upazila maps to several zones (rule 32). */
            'courier_location_id' => ['nullable', 'integer', 'min:1', 'exists:courier_locations,id'],
            /** Parcel weight in grams, overriding Σ variant weight × quantity. */
            'weight_grams' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_WEIGHT_GRAMS],
            /** Note for the courier (delivery instructions). */
            'note' => ['nullable', 'string', 'max:'.RequestShipment::NOTE_MAX],
        ];
    }

    public function provider(): ?string
    {
        $v = $this->input('provider');

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function courierLocationId(): ?int
    {
        return $this->filled('courier_location_id') ? $this->integer('courier_location_id') : null;
    }

    public function weightGrams(): ?int
    {
        return $this->filled('weight_grams') ? $this->integer('weight_grams') : null;
    }

    public function note(): ?string
    {
        $v = $this->input('note');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
