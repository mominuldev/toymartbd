<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\CreditNoteReason;
use App\Domain\Invoicing\Enums\CreditNoteSeries;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Exceptions\CreditNoteExceedsInvoice;
use App\Domain\Invoicing\Models\CreditNote;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\AmountInWords;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Invoicing\Services\CreditNoteIssuer;
use App\Domain\Invoicing\Services\InvoiceNumberGenerator;
use App\Domain\Invoicing\Services\OrderEditDelta;
use App\Domain\Ordering\Enums\OrderEventType;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * An order edited AFTER its customer invoice was issued (owner decision 2026-11-13, P8
 * batch 3): the reduction gets a credit note (`order_edited`), the increase a supplementary
 * invoice; one edit can produce both ({@see OrderEditDelta}). Reached from Ordering's
 * OrderEdited event (IssueDocumentsOnOrderEdited), never called by Ordering (rule 2).
 *
 * SOURCE: the edit's `order_events` row (type `edited`, before/after in `meta`). Both
 * documents carry it as (`order_edit`, row id), UNIQUE on each table — a replayed event finds
 * what was issued and issues nothing more.
 *
 * WHICH EDITS: only those after the invoice — a row id above the invoice's `order_event_id`
 * (the last timeline row its snapshot reflects, taken under the order lock), or, for an
 * invoice issued before that column existed, a row not older than `issued_at`. An edit the
 * invoice already shows needs nothing. {@see self::forOrder()} walks every such edit of the
 * order in order, so a missed one is caught up by the next (each is idempotent).
 *
 * NOTHING when: the order has no invoice; the edit moved no money (address only); or the
 * invoice was already credited in full for a cancellation or a courier return (the order is
 * closed on paper — a late replay must not reopen it).
 *
 * One transaction (3 attempts): `CN` counter (if a credit note is due), `INV` counter (if a
 * supplementary invoice is due), then the original invoice row, FOR UPDATE — the global lock
 * order of every Invoicing writer. The supplementary invoice is written first, so the credit
 * note is capped by the balance including it. A refusal rolls both back with their numbers:
 * gap-free. Documents only: no `financial_transactions` row (the order's own figures are what
 * Finance reads).
 */
final readonly class IssueOrderEditDocuments
{
    public function __construct(
        private CreditNoteIssuer $issuer,
        private InvoiceNumberGenerator $numbers,
        private CompanyDetails $company,
    ) {}

    /**
     * Every edit of the order made after its invoice, oldest first.
     *
     * @return list<array{credit_note: CreditNote|null, supplementary: Invoice|null}>
     *
     * @throws CreditNoteExceedsInvoice
     */
    public function forOrder(int $orderId): array
    {
        $invoice = $this->issuer->salesInvoice($orderId);
        if ($invoice === null) {
            return [];
        }

        $edits = OrderEvent::query()->where('order_id', $orderId)->where('type', OrderEventType::Edited->value)
            ->when(
                $invoice->order_event_id !== null,
                static fn ($q) => $q->where('id', '>', $invoice->order_event_id),
                static fn ($q) => $q->where('created_at', '>=', $invoice->issued_at),
            )
            ->orderBy('id')->pluck('id');

        return $edits->map(fn (mixed $id): array => $this->handle((int) $id))->values()->all();
    }

    /**
     * @return array{credit_note: CreditNote|null, supplementary: Invoice|null}
     *
     * @throws CreditNoteExceedsInvoice
     */
    public function handle(int $editEventId): array
    {
        $none = ['credit_note' => null, 'supplementary' => null];

        /** @var OrderEvent|null $edit */
        $edit = OrderEvent::query()->whereKey($editEventId)->where('type', OrderEventType::Edited->value)->first();
        $invoice = $edit === null ? null : $this->issuer->salesInvoice($edit->order_id);
        if ($edit === null || $invoice === null || ! self::afterInvoice($edit, $invoice)) {
            return $none;
        }

        /** @var Order $order */
        $order = Order::query()->with(['items', 'customer'])->findOrFail($edit->order_id);
        $delta = OrderEditDelta::fromEdit($edit->meta ?? [], $order->items);
        $wantCredit = $delta->needsCreditNote();
        $wantInvoice = $delta->needsSupplementaryInvoice();
        if (! $wantCredit && ! $wantInvoice) {
            return $none;
        }

        $existing = $this->existing($edit->id);
        if (($existing['credit_note'] !== null || ! $wantCredit) && ($existing['supplementary'] !== null || ! $wantInvoice)) {
            return $existing;
        }

        if ($wantCredit) {
            $this->issuer->prepare();
        }
        if ($wantInvoice) {
            $this->numbers->prepare(InvoiceType::Sales);
        }

        return DB::transaction(function () use ($edit, $invoice, $order, $delta, $wantCredit, $wantInvoice): array {
            // Global lock order: CN counter → INV counter → the original invoice row.
            $creditCounter = $wantCredit ? $this->numbers->lock(CreditNoteSeries::CreditNote) : null;
            $invoiceCounter = $wantInvoice ? $this->numbers->lock(InvoiceType::Sales) : null;
            ['invoice' => $locked, 'remaining' => $remaining] = $this->issuer->lockInvoice($invoice->id);

            $found = $this->existing($edit->id);
            if (self::closed($locked->id)) {
                return $found;
            }

            $supplementary = $found['supplementary'];
            if ($invoiceCounter !== null && $supplementary === null) {
                $supplementary = $this->writeSupplementary($invoiceCounter, $locked, $order, $edit, $delta);
                $remaining = $remaining->plus($supplementary->total);
            }

            $note = $found['credit_note'];
            if ($creditCounter !== null && $note === null) {
                $note = $this->issuer->write(
                    $creditCounter, $locked, $remaining, CreditNoteReason::OrderEdited, $edit->id, $order->number, $edit->description,
                    $delta->creditLines,
                    [
                        'subtotal' => $delta->creditSubtotal(),
                        'discount' => Money::zero(),
                        'shipping' => $delta->creditShipping,
                        'tax' => Money::zero(),
                        'adjustment' => $delta->creditAdjustment,
                        'total' => $delta->creditTotal(),
                    ],
                    $edit->user_id,
                );
            }

            return ['credit_note' => $note, 'supplementary' => $supplementary];
        }, 3);
    }

    /** @param  array{year: int, next: int}  $counter */
    private function writeSupplementary(array $counter, Invoice $original, Order $order, OrderEvent $edit, OrderEditDelta $delta): Invoice
    {
        $total = $delta->invoiceTotal();
        $number = $this->numbers->take(InvoiceType::Sales, $counter['year'], $counter['next']);

        return Invoice::query()->create([
            'type' => InvoiceType::Sales,
            'kind' => InvoiceKind::Supplementary,
            'original_invoice_id' => $original->id,
            'source_type' => CreditNoteReason::OrderEdited->sourceType(),
            'source_id' => $edit->id,
            'series' => InvoiceType::Sales->series(),
            'year' => $counter['year'],
            'sequence' => $counter['next'],
            'number' => $number,
            'order_id' => $order->id,
            'order_event_id' => $edit->id,
            'reference' => $order->number,
            'issued_by' => $this->company->snapshot(),
            'issued_to' => IssueSalesInvoice::issuedTo($order) + ['original_invoice_number' => $original->number, 'original_issued_at' => $original->issued_at->toIso8601String()],
            'lines' => $delta->invoiceLines,
            'currency' => $original->currency,
            'subtotal' => $delta->invoiceSubtotal(),
            'discount' => Money::zero(),
            'shipping' => $delta->invoiceShipping,
            'tax' => Money::zero(),
            'total' => $total,
            'paid' => Money::zero(),
            'due' => $total,
            'payment_method' => $order->payment_method,
            'amount_in_words' => AmountInWords::english($total),
            'amount_in_words_bn' => AmountInWords::bangla($total),
            'issued_at' => now(),
            'template' => Invoice::TEMPLATE_STANDARD,
            'created_by' => $edit->user_id,
        ]);
    }

    /** @return array{credit_note: CreditNote|null, supplementary: Invoice|null} */
    private function existing(int $editEventId): array
    {
        $source = CreditNoteReason::OrderEdited->sourceType();

        return [
            'credit_note' => $this->issuer->existing(CreditNoteReason::OrderEdited, $editEventId),
            'supplementary' => Invoice::query()->where('source_type', $source)->where('source_id', $editEventId)->first(),
        ];
    }

    private static function afterInvoice(OrderEvent $edit, Invoice $invoice): bool
    {
        if ($invoice->order_event_id !== null) {
            return $edit->id > $invoice->order_event_id;
        }

        return $edit->created_at !== null && $edit->created_at->greaterThanOrEqualTo($invoice->issued_at);
    }

    /** Credited in full for a cancellation or a courier return: the order is closed on paper. */
    private static function closed(int $invoiceId): bool
    {
        return CreditNote::query()->where('invoice_id', $invoiceId)
            ->whereIn('reason', [CreditNoteReason::OrderCancelled->value, CreditNoteReason::CourierReturn->value])->exists();
    }
}
