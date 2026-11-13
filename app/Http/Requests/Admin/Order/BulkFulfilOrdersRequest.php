<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use App\Domain\Ordering\Actions\MarkOrderShipped;
use App\Domain\Ordering\Models\Order;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /orders/bulk/ship` and `POST /orders/bulk/deliver` — manual fulfilment of many
 * orders from the board. Needs orders.update + shipments.book. Up to 100 ids; unknown ids
 * come back as `not_found` results. `courier_name` (ship only) applies to every order.
 */
final class BulkFulfilOrdersRequest extends ApiFormRequest
{
    public const int MAX_ORDERS = 100;

    public function authorize(): bool
    {
        return $this->user()?->can('fulfilMany', Order::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ORDERS],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            /** Bulk ship only: the courier every parcel was handed to. Ignored by bulk deliver. */
            'courier_name' => ['nullable', 'string', 'max:'.MarkOrderShipped::FIELD_MAX],
        ];
    }

    /** @return list<int> */
    public function orderIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = array_values((array) $this->input('order_ids', []));

        return array_map(intval(...), $ids);
    }

    public function courierName(): ?string
    {
        $name = $this->input('courier_name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
