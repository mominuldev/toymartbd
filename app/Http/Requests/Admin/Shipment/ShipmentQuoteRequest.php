<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Shipment;

use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;

/**
 * `POST /shipments/quote` — the booking panel's charge preview for one order (single) or up
 * to 100 (bulk), for every courier, from the stored rate cards. Read-only; needs the same
 * powers as booking (orders.update + shipments.book). `weight_grams` overrides the summed
 * variant weight for that order ("Override if you weighed the parcel").
 */
final class ShipmentQuoteRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can(Permission::OrdersUpdate->value) && $user->can(Permission::ShipmentsBook->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'orders' => ['required', 'array', 'min:1', 'max:100'],
            'orders.*.id' => ['required', 'integer', 'distinct'],
            /** Parcel weight in grams; absent = Σ variant weight × quantity. */
            'orders.*.weight_grams' => ['nullable', 'integer', 'min:1', 'max:50000'],
        ];
    }

    /** @return array<int, int|null> order id => weight override */
    public function weights(): array
    {
        $out = [];
        foreach ((array) $this->validated('orders') as $row) {
            $out[(int) $row['id']] = isset($row['weight_grams']) ? (int) $row['weight_grams'] : null;
        }

        return $out;
    }
}
