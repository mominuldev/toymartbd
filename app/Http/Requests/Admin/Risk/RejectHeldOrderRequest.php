<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Risk;

use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Models\Order;
use App\Domain\Risk\Models\RiskVerificationCall;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /risk/orders/{order}/reject` — a person cancels a held order: `reason` (a
 * CancellationReason, required) and `note` (required for `other`). Needs risk.verify AND
 * orders.cancel. The only cancel the queue has — scoring never cancels (rule 23).
 */
final class RejectHeldOrderRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('order');

        return $user !== null && $order instanceof Order
            && $user->can('create', RiskVerificationCall::class)
            && $user->can('cancel', $order);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(CancellationReason::values())],
            'note' => ['nullable', 'string', 'max:500', 'required_if:reason,other'],
        ];
    }

    public function reason(): CancellationReason
    {
        return CancellationReason::from((string) $this->validated('reason'));
    }

    public function note(): ?string
    {
        $v = $this->validated('note');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
