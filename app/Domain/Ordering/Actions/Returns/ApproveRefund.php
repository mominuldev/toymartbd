<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions\Returns;

use App\Domain\Ordering\Enums\OrderEventType;
use App\Domain\Ordering\Events\RefundApproved;
use App\Domain\Ordering\Exceptions\ReturnNotAllowed;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Services\ReturnQuantities;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\Refund;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Support\DhakaDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Approve a requested refund (§4.7: "refunds above a configurable threshold require
 * approved_by before processing"). Above `returns.approval_threshold` the approver must
 * hold refunds.approve — RefundPolicy refuses the request first; this Action re-asserts
 * it so no other caller can skip the rule. Writes approved_by / approved_at and an
 * audit_sensitive row; RefundApproved (after commit) books the refund in the ledger.
 */
final readonly class ApproveRefund
{
    public function __construct(private ReturnQuantities $quantities) {}

    public static function threshold(): Money
    {
        $value = config('returns.approval_threshold', '2000.00');

        return Money::of(is_string($value) || is_int($value) ? $value : '2000.00');
    }

    public static function needsManager(Refund $refund): bool
    {
        return $refund->amount->greaterThan(self::threshold());
    }

    /** @throws ReturnNotAllowed */
    public function handle(Refund $refund, int $userId, bool $mayApproveAboveThreshold): Refund
    {
        return DB::transaction(function () use ($refund, $userId, $mayApproveAboveThreshold): Refund {
            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($refund->order_id);
            /** @var Refund $locked */
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if (! $locked->status->canTransitionTo(RefundStatus::Approved)) {
                throw ReturnNotAllowed::refundStatus($locked->id, $locked->status->value, RefundStatus::Approved->value);
            }
            if (self::needsManager($locked) && ! $mayApproveAboveThreshold) {
                throw ReturnNotAllowed::aboveThreshold($locked->id, self::threshold()->toDecimal());
            }
            $refundable = $this->quantities->refundable($order->id, exceptRefundId: $locked->id);
            if ($locked->amount->greaterThan($refundable)) {
                throw ReturnNotAllowed::exceedsCaptured($locked->id, $refundable->toDecimal());
            }

            $locked->status = RefundStatus::Approved;
            $locked->approved_by = $userId;
            $locked->approved_at = now();
            $locked->save();

            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::RefundApproved,
                'model_type' => $locked->getMorphClass(),
                'model_id' => $locked->id,
                'before' => ['status' => RefundStatus::Requested->value],
                'after' => ['status' => RefundStatus::Approved->value, 'amount' => $locked->amount->toDecimal()],
            ]);
            OrderEvent::query()->create([
                'order_id' => $order->id,
                'type' => OrderEventType::RefundApproved,
                'meta' => ['refund_id' => $locked->id, 'amount' => $locked->amount->toDecimal()],
                'user_id' => $userId,
                'is_customer_visible' => false,
            ]);

            RefundApproved::dispatch(
                $locked->id, $order->id, $locked->amount->toDecimal(), DhakaDate::today(), $order->delivered_at !== null, $userId,
            );

            return $locked;
        });
    }
}
