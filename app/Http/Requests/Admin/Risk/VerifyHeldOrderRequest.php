<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Risk;

use App\Domain\Ordering\Models\Order;
use App\Domain\Risk\Models\RiskVerificationCall;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /risk/orders/{order}/verify` — optional `note`. Verifying confirms the order
 * (on_hold → confirmed), so it needs risk.verify AND orders.confirm.
 */
final class VerifyHeldOrderRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('order');

        return $user !== null && $order instanceof Order
            && $user->can('create', RiskVerificationCall::class)
            && $user->can('confirm', $order);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:500']];
    }

    public function note(): ?string
    {
        $v = $this->validated('note');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
