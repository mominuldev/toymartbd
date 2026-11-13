<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\CreditNoteReason;
use App\Domain\Invoicing\Exceptions\CreditNoteExceedsInvoice;
use App\Domain\Invoicing\Models\CreditNote;
use App\Domain\Invoicing\Services\CreditNoteIssuer;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * An order was cancelled AFTER its customer invoice was issued: credit the whole of what
 * the invoice still has uncredited (P8 batch 2). Reached from Ordering's OrderCancelled
 * event (IssueCreditNoteOnOrderCancelled), never called by Ordering (rule 2).
 *
 *  - No issued invoice → nothing (null): a cancelled order with no invoice needs no credit.
 *  - Idempotent: a re-delivered event finds the credit note already issued for this
 *    order's cancellation and returns it (UNIQUE source).
 *  - Nothing left uncredited (e.g. refunds already credited everything) → null.
 *  - Lines: an invoice credited for the first time is credited line for line, with its
 *    discount, delivery charge and tax, so the credit note mirrors it exactly; one already
 *    partly credited — or with a supplementary invoice on it (P8 batch 3) — gets a single
 *    "balance" line for the remainder ({@see CreditNoteIssuer::fullBalance()}).
 *
 * One transaction (3 attempts on deadlock): the `CN` counter row, then the invoice row,
 * locked FOR UPDATE; a failure returns the number (gap-free). A document only — no ledger
 * row is written (the cancellation books its own).
 */
final readonly class IssueCancellationCreditNote
{
    public function __construct(private CreditNoteIssuer $issuer) {}

    /** @throws CreditNoteExceedsInvoice never in practice — the remainder is what is credited */
    public function handle(int $orderId, ?int $userId = null): ?CreditNote
    {
        $reason = CreditNoteReason::OrderCancelled;
        $existing = $this->issuer->existing($reason, $orderId);
        if ($existing !== null) {
            return $existing;
        }

        $invoice = $this->issuer->salesInvoice($orderId);
        /** @var Order|null $order */
        $order = Order::query()->find($orderId);
        if ($invoice === null || $order === null || $order->status !== OrderStatus::Cancelled) {
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

    /** @return array<string, mixed> */
    public static function balanceLine(string $invoiceNumber, Money $amount): array
    {
        return CreditNoteIssuer::balanceLine($invoiceNumber, $amount);
    }
}
