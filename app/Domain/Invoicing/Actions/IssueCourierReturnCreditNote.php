<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\CreditNoteReason;
use App\Domain\Invoicing\Exceptions\CreditNoteExceedsInvoice;
use App\Domain\Invoicing\Models\CreditNote;
use App\Domain\Invoicing\Services\CreditNoteIssuer;
use App\Domain\Ordering\Enums\OrderEventType;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The courier returned an invoiced order to us (RTO — refused or undeliverable; owner
 * decision 2026-11-13, P8 batch 3): credit everything the invoice still stands at — the
 * original plus any supplementary invoices, less what was already credited. Reached from
 * Ordering's OrderReturned event (IssueCreditNoteOnOrderReturned), never called by Ordering.
 *
 *  - Only a COURIER return: the order is `returned` and the timeline row of that move carries
 *    `courier_return` (RecordCourierReturn). A customer return reaches `returned` through
 *    inspection and is credited by its refund (IssueRefundCreditNote), never twice.
 *  - No issued invoice → nothing (null). Nothing left uncredited (a cancellation credit, or
 *    refunds, already covered it) → nothing (null), and no number is used.
 *  - Idempotent: source = the order (`courier_return`, UNIQUE), so a replayed event returns
 *    the credit note already issued.
 *  - Lines as for a cancellation ({@see CreditNoteIssuer::fullBalance()}).
 *
 * One transaction (3 attempts): `CN` counter, then the invoice row, FOR UPDATE; gap-free.
 * A document only — the ledger side of a courier return is Finance's (courier_return_charge,
 * revenue reversed only if it had been recognised); no `financial_transactions` row here.
 */
final readonly class IssueCourierReturnCreditNote
{
    public function __construct(private CreditNoteIssuer $issuer) {}

    /** @throws CreditNoteExceedsInvoice never in practice — the remainder is what is credited */
    public function handle(int $orderId, ?int $userId = null): ?CreditNote
    {
        $reason = CreditNoteReason::CourierReturn;
        $existing = $this->issuer->existing($reason, $orderId);
        if ($existing !== null) {
            return $existing;
        }

        $invoice = $this->issuer->salesInvoice($orderId);
        /** @var Order|null $order */
        $order = Order::query()->find($orderId);
        if ($invoice === null || $order === null || $order->status !== OrderStatus::Returned || ! self::wasCourierReturn($orderId)) {
            return null;
        }

        $this->issuer->prepare();

        return DB::transaction(function () use ($reason, $orderId, $invoice, $order, $userId): ?CreditNote {
            ['counter' => $counter, 'invoice' => $locked, 'remaining' => $remaining] = $this->issuer->lock($invoice->id);

            $existing = $this->issuer->existing($reason, $orderId);
            if ($existing !== null) {
                return $existing;
            }
            if (! $remaining->greaterThan(Money::zero())) {
                return null;
            }

            ['lines' => $lines, 'totals' => $totals] = CreditNoteIssuer::fullBalance($locked, $remaining);

            return $this->issuer->write(
                $counter, $locked, $remaining, $reason, $orderId, $order->number, null, $lines, $totals, $userId,
            );
        }, 3);
    }

    /** Whether the order's move to `returned` was RecordCourierReturn's (meta `courier_return`). */
    private static function wasCourierReturn(int $orderId): bool
    {
        /** @var OrderEvent|null $move */
        $move = OrderEvent::query()->where('order_id', $orderId)->where('type', OrderEventType::StatusChanged->value)
            ->where('to_status', OrderStatus::Returned->value)->latest('id')->first();

        return ($move?->meta['courier_return'] ?? false) === true;
    }
}
