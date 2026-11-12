<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Returns;

use App\Domain\Ordering\Enums\ReturnReason;
use App\Domain\Ordering\Models\OrderReturn;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /orders/{order}/returns` — needs returns.manage. Quantities are checked against
 * what is still returnable on each line by RequestReturn, under the order's row lock.
 */
final class StoreReturnRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', OrderReturn::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.order_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'reason' => ['required', Rule::enum(ReturnReason::class)],
            /** Required when `reason` is `other`. */
            'reason_note' => ['nullable', 'string', 'max:500', 'required_if:reason,other'],
            /** Also refund the delivery charge (at most once per order). */
            'refund_shipping' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, int> order item id → quantity */
    public function lines(): array
    {
        $lines = [];
        foreach ((array) $this->input('items', []) as $row) {
            if (is_array($row)) {
                $lines[(int) $row['order_item_id']] = (int) $row['quantity'];
            }
        }

        return $lines;
    }

    public function reason(): ReturnReason
    {
        return ReturnReason::from($this->string('reason')->toString());
    }

    public function reasonNote(): ?string
    {
        $note = $this->input('reason_note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
