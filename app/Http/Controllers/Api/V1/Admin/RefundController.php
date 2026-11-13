<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ordering\Actions\Returns\ApproveRefund;
use App\Domain\Ordering\Actions\Returns\PayRefund;
use App\Domain\Ordering\Actions\Returns\RejectRefund;
use App\Domain\Payment\Models\Refund;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Returns\ApproveRefundRequest;
use App\Http\Requests\Admin\Returns\PayRefundRequest;
use App\Http\Requests\Admin\Returns\RejectRefundRequest;
use App\Http\Resources\Admin\RefundResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

/**
 * Refund approval and payout (DATABASE-DESIGN §4.7). Above `returns.approval_threshold`
 * approving needs refunds.approve; at or below it refunds.create is enough. Paying out
 * needs an `Idempotency-Key`, and paying a completed refund again changes nothing.
 */
final class RefundController extends ApiController
{
    /** `POST /refunds/{refund}/approve`. */
    #[OpenApiResponse(409, 'Not requested any more, or more than is still refundable (`conflict`)')]
    public function approve(ApproveRefundRequest $request, Refund $refund, ApproveRefund $action): RefundResource
    {
        $userId = (int) $request->user()?->getAuthIdentifier();

        return $this->fresh($action->handle($refund, $userId, $request->mayApproveAboveThreshold()));
    }

    /** `POST /refunds/{refund}/reject` — a requested refund only, with a reason. */
    #[OpenApiResponse(409, 'Already approved or decided (`conflict`)')]
    public function reject(RejectRefundRequest $request, Refund $refund, RejectRefund $action): RefundResource
    {
        return $this->fresh($action->handle($refund, $request->note(), $request->user()?->id));
    }

    /**
     * `POST /refunds/{refund}/pay` — needs an `Idempotency-Key`. Manual payout (COD/cash
     * orders) needs `method` and `reference`; an online payment is refunded through its
     * gateway. Never more than was captured.
     */
    #[OpenApiResponse(409, 'Not approved, or more than was captured (`conflict`)')]
    public function pay(PayRefundRequest $request, Refund $refund, PayRefund $action): RefundResource
    {
        return $this->fresh($action->handle($refund, $request->via(), $request->reference(), $request->user()?->id));
    }

    private function fresh(Refund $refund): RefundResource
    {
        return new RefundResource($refund->refresh()->load(['items', 'payment']));
    }
}
