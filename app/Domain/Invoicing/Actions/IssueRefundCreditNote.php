<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\CreditNoteReason;
use App\Domain\Invoicing\Exceptions\CreditNoteExceedsInvoice;
use App\Domain\Invoicing\Models\CreditNote;
use App\Domain\Invoicing\Services\CreditNoteIssuer;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Models\OrderReturn;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\Refund;
use App\Domain\Payment\Models\RefundItem;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * A refund on a return was paid out for an order whose customer invoice was issued: credit
 * the refunded lines — and the delivery charge, if it was refunded (P8 batch 2). Reached
 * from Ordering's RefundPaid event (IssueCreditNoteOnRefundPaid), never called by Ordering.
 *
 *  - Only a `completed` refund; no issued invoice → nothing (null).
 *  - Idempotent: a re-delivered event returns the credit note already issued for this
 *    refund (UNIQUE source).
 *  - Lines are the refund's `refund_items` (quantity and amount as the refund computed
 *    them) with the order line's name, SKU and unit price copied; `shipping` is the
 *    refund's `shipping_amount`. The credit note's total is EXACTLY the refund amount: if the
 *    refund was capped below its computed lines, the difference is an explicit adjustment.
 *  - Refused (CreditNoteExceedsInvoice, nothing written) when the refund is more than the
 *    invoice still has uncredited — e.g. the order was edited upwards after issue.
 *
 * One transaction (3 attempts): `CN` counter, then invoice row, FOR UPDATE. No ledger row
 * (the refund books its own).
 */
final readonly class IssueRefundCreditNote
{
    public function __construct(private CreditNoteIssuer $issuer) {}

    /** @throws CreditNoteExceedsInvoice */
    public function handle(int $refundId, ?int $userId = null): ?CreditNote
    {
        $reason = CreditNoteReason::Refund;
        $existing = $this->issuer->existing($reason, $refundId);
        if ($existing !== null) {
            return $existing;
        }

        /** @var Refund|null $refund */
        $refund = Refund::query()->with('items')->find($refundId);
        if ($refund === null || $refund->status !== RefundStatus::Completed) {
            return null;
        }
        $invoice = $this->issuer->salesInvoice($refund->order_id);
        if ($invoice === null) {
            return null;
        }

        $items = OrderItem::query()->whereKey($refund->items->pluck('order_item_id')->all())->get()->keyBy('id');
        $lines = [];
        $subtotal = Money::zero();
        foreach ($refund->items->sortBy('id') as $refunded) {
            /** @var RefundItem $refunded */
            /** @var OrderItem|null $item */
            $item = $items->get($refunded->order_item_id);
            $lines[] = [
                'kind' => 'item',
                'name' => $item !== null ? $item->product_name : '',
                'name_bn' => $item?->product_name_bn,
                'variant' => $item?->variant_name,
                'sku' => $item?->sku,
                'quantity' => $refunded->quantity,
                'unit_price' => $item !== null ? $item->unit_price->toDecimal() : $refunded->amount->toDecimal(),
                'amount' => $refunded->amount->toDecimal(),
            ];
            $subtotal = $subtotal->plus($refunded->amount);
        }
        $shipping = $refund->shipping_amount;
        $totals = [
            'subtotal' => $subtotal,
            'discount' => Money::zero(),
            'shipping' => $shipping,
            'tax' => Money::zero(),
            'adjustment' => $refund->amount->minus($subtotal)->minus($shipping),
            'total' => $refund->amount,
        ];
        $rma = OrderReturn::query()->where('refund_id', $refund->id)->value('number');
        $sourceReference = is_string($rma) ? $rma : null;

        $this->issuer->prepare();

        return DB::transaction(function () use ($reason, $refund, $invoice, $lines, $totals, $sourceReference, $userId): CreditNote {
            ['counter' => $counter, 'invoice' => $locked, 'remaining' => $remaining] = $this->issuer->lock($invoice->id);

            $existing = $this->issuer->existing($reason, $refund->id);
            if ($existing !== null) {
                return $existing;
            }

            return $this->issuer->write(
                $counter, $locked, $remaining, $reason, $refund->id, $sourceReference, $refund->reason, $lines, $totals, $userId,
            );
        }, 3);
    }
}
