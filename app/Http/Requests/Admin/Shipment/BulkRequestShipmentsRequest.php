<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Shipment;

use App\Domain\Fulfilment\Models\Shipment;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /shipments/bulk` — send many orders to one courier from the board. Needs
 * orders.update + shipments.book; `Idempotency-Key` required. Up to 100 ids; unknown ids
 * come back as `not_found` results.
 */
final class BulkRequestShipmentsRequest extends ApiFormRequest
{
    public const int MAX_ORDERS = 100;

    public function authorize(): bool
    {
        return $this->user()?->can('request', Shipment::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ORDERS],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            /** Courier provider code. Omitted: the active default provider. */
            'provider' => ['nullable', 'string', 'max:32', 'exists:courier_providers,code'],
        ];
    }

    /** @return list<int> */
    public function orderIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = array_values((array) $this->input('order_ids', []));

        return array_map(intval(...), $ids);
    }

    public function provider(): ?string
    {
        $v = $this->input('provider');

        return is_string($v) && $v !== '' ? $v : null;
    }
}
