<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Returns;

use App\Domain\Payment\Models\Refund;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /refunds/{refund}/approve` — refunds.approve, or refunds.create when the amount is
 * at or below the approval threshold (RefundPolicy::approve). No body.
 */
final class ApproveRefundRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $refund = $this->route('refund');

        return $refund instanceof Refund && ($this->user()?->can('approve', $refund) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function mayApproveAboveThreshold(): bool
    {
        return $this->user()?->can('approveAboveThreshold', Refund::class) ?? false;
    }
}
