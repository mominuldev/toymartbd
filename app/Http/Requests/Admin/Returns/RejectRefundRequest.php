<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Returns;

use App\Domain\Payment\Models\Refund;
use App\Http\Requests\ApiFormRequest;

/** `POST /refunds/{refund}/reject` — same permission as approving it; a reason is required. */
final class RejectRefundRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $refund = $this->route('refund');

        return $refund instanceof Refund && ($this->user()?->can('approve', $refund) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'min:3', 'max:500']];
    }

    public function note(): string
    {
        return trim($this->string('note')->toString());
    }
}
