<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Returns;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\Refund;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /refunds/{refund}/pay` — refunds.create or refunds.approve; needs an
 * `Idempotency-Key`. For a COD (or cash) order staff paid by hand: `method` (bkash, nagad,
 * cash, bank_transfer) and the transaction `reference` (not needed for cash) are required
 * then (checked by PayRefund). For an online payment both are ignored — the gateway pays.
 */
final class PayRefundRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $refund = $this->route('refund');

        return $refund instanceof Refund && ($this->user()?->can('pay', $refund) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['nullable', Rule::in(['bkash', 'nagad', 'cash', 'bank_transfer'])],
            'reference' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function via(): ?PaymentMethod
    {
        $method = $this->input('method');

        return is_string($method) ? PaymentMethod::tryFrom($method) : null;
    }

    public function reference(): ?string
    {
        $reference = $this->input('reference');

        return is_string($reference) && trim($reference) !== '' ? trim($reference) : null;
    }
}
