<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderNote;
use App\Http\Requests\ApiFormRequest;

/** `POST /orders/{order}/notes` — needs orders.update. Notes are append-only. */
final class StoreOrderNoteRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof Order && ($this->user()?->can('addNote', $order) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.OrderNote::MAX_LENGTH],
            /** Private by default; true lets the customer's tracking page show it. */
            'is_customer_visible' => ['sometimes', 'boolean'],
        ];
    }
}
