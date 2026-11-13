<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Ordering\Actions\Returns\ApproveRefund;
use App\Domain\Ordering\Actions\Returns\PayRefund;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\Refund;
use App\Domain\Payment\Models\RefundItem;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A refund (§3.5, §4.7). Every figure is the server's (rule 1). `needs_manager` says the
 * amount is above the approval threshold; `can` says what THIS staff member may do now,
 * so the screen never re-derives a permission or a threshold. `payout` is `manual` when
 * staff pay by hand (a COD or cash order) and enter the method and reference, `gateway`
 * when the online provider is called.
 *
 * @property Refund $resource
 */
#[SchemaName('Refund')]
final class RefundResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $refund = $this->resource;
        $user = $request->user();
        $payment = $refund->relationLoaded('payment') ? $refund->payment : $refund->payment()->first();

        return [
            'id' => $refund->id,
            'order_id' => $refund->order_id,
            'amount' => $refund->amount->toDecimal(),
            'shipping_amount' => $refund->shipping_amount->toDecimal(),
            /** @var 'full'|'partial' */
            'type' => $refund->type->value,
            /** @var 'cod'|'sslcommerz'|'bkash'|'nagad'|'card'|'bank_transfer'|'cash' */
            'method' => $refund->method->value,
            /** @var 'requested'|'approved'|'processing'|'completed'|'failed'|'rejected' */
            'status' => $refund->status->value,
            'reason' => $refund->reason,
            /** @var string|null */
            'gateway_reference' => $refund->gateway_reference,
            'restock' => $refund->restock,
            /** @var string|null */
            'note' => $refund->note,
            'needs_manager' => ApproveRefund::needsManager($refund),
            'approval_threshold' => ApproveRefund::threshold()->toDecimal(),
            /** @var 'manual'|'gateway' */
            'payout' => $payment instanceof Payment && ! PayRefund::isManual($payment) ? 'gateway' : 'manual',
            /** @var int|null */
            'requested_by' => $refund->requested_by,
            /** @var int|null */
            'approved_by' => $refund->approved_by,
            /** @var string|null */
            'approved_at' => $refund->approved_at?->toIso8601String(),
            /** @var string|null */
            'processed_at' => $refund->processed_at?->toIso8601String(),
            /** @var string|null */
            'created_at' => $refund->created_at?->toIso8601String(),
            'items' => $refund->items->map(static fn (RefundItem $i): array => [
                'order_item_id' => $i->order_item_id,
                'quantity' => $i->quantity,
                'amount' => $i->amount->toDecimal(),
            ])->all(),
            'can' => [
                'approve' => $refund->status === RefundStatus::Requested && ($user?->can('approve', $refund) ?? false),
                'pay' => in_array($refund->status, [RefundStatus::Approved, RefundStatus::Failed], true) && ($user?->can('pay', $refund) ?? false),
            ],
        ];
    }
}
