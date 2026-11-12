<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use App\Domain\Ordering\Models\Order;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /orders/bulk/confirm` — needs orders.confirm. Up to 100 ids (one board page is
 * 25). Unknown ids are not a validation error: they come back as `not_found` results,
 * so a stale selection still reports per order.
 */
final class BulkConfirmOrdersRequest extends ApiFormRequest
{
    public const int MAX_ORDERS = 100;

    public function authorize(): bool
    {
        return $this->user()?->can('confirmMany', Order::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ORDERS],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /** @return list<int> */
    public function orderIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = array_values((array) $this->input('order_ids', []));

        return array_map(intval(...), $ids);
    }
}
