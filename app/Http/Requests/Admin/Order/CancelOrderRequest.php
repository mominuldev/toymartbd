<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Models\Order;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /orders/{order}/cancel` — needs orders.cancel. `reason` is the fixed list
 * (cancellations are counted by cause); `note` is required when the reason is `other`.
 */
final class CancelOrderRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof Order && ($this->user()?->can('cancel', $order) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(CancellationReason::class)],
            /** Required when `reason` is `other`. */
            'note' => ['nullable', 'string', 'max:500', 'required_if:reason,other'],
        ];
    }

    public function reason(): CancellationReason
    {
        return CancellationReason::from($this->string('reason')->toString());
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
