<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use App\Domain\Ordering\Models\Order;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /orders/{order}/deliver` — manual delivery confirmation (P5, until Phase 6
 * courier status updates). Needs orders.update + shipments.book.
 */
final class DeliverOrderRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof Order && ($this->user()?->can('fulfil', $order) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Shown on the order's activity timeline next to the change. */
            'note' => ['nullable', 'string', 'max:'.OrderWorkflowRequest::NOTE_MAX],
        ];
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
