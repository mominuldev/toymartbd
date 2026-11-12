<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use App\Domain\Ordering\Models\Order;

/** `POST /orders/{order}/confirm` — needs orders.confirm; optional `note`. */
final class ConfirmOrderRequest extends OrderWorkflowRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof Order && ($this->user()?->can('confirm', $order) ?? false);
    }
}
