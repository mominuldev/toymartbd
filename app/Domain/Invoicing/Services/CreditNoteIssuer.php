<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Enums\CreditNoteReason;
use App\Domain\Invoicing\Enums\CreditNoteSeries;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Exceptions\CreditNoteExceedsInvoice;
use App\Domain\Invoicing\Models\CreditNote;
use App\Domain\Invoicing\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The steps every credit-note Action shares (IssueCancellationCreditNote,
 * IssueRefundCreditNote, IssueCourierReturnCreditNote, IssueOrderEditDocuments). It opens no transaction — the Action owns it (rule 3) — and
 * {@see self::lock()} / {@see self::write()} refuse to run outside one.
 *
 * Order of locks inside the Action's transaction, the same for every issuer so two cannot
 * deadlock: the `CN` counter row first (serialises every credit note), then the `INV`
 * counter if the same transaction also issues a supplementary invoice, then the invoice row.
 *
 * Credit notes always reference the order's ORIGINAL invoice; what it still has uncredited
 * is original + supplementary invoices − credit notes (P8 batch 3).
 */
final readonly class CreditNoteIssuer
{
    public function __construct(
        private InvoiceNumberGenerator $numbers,
        private CompanyDetails $company,
    ) {}

    /** The credit note already issued for this cause, if any (UNIQUE source). */
    public function existing(CreditNoteReason $reason, int $sourceId): ?CreditNote
    {
        return CreditNote::query()->where('source_type', $reason->sourceType())->where('source_id', $sourceId)->first();
    }

    /** The order's issued (original) customer invoice, if any. */
    public function salesInvoice(int $orderId): ?Invoice
    {
        return Invoice::query()->where('type', InvoiceType::Sales->value)->where('kind', InvoiceKind::Original->value)->where('order_id', $orderId)->first();
    }

    /** Creates this Dhaka year's `CN` counter row — BEFORE the transaction (first-of-year deadlock). */
    public function prepare(): void
    {
        $this->numbers->prepare(CreditNoteSeries::CreditNote);
    }

    /**
     * Locks the `CN` counter, then the invoice row, and returns the counter, the locked
     * invoice and what of it is still uncredited.
     *
     * @return array{counter: array{year: int, next: int}, invoice: Invoice, remaining: Money}
     */
    public function lock(int $invoiceId): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Credit notes are issued inside a transaction only.');
        }

        $counter = $this->numbers->lock(CreditNoteSeries::CreditNote);
        ['invoice' => $invoice, 'remaining' => $remaining] = $this->lockInvoice($invoiceId);

        return ['counter' => $counter, 'invoice' => $invoice, 'remaining' => $remaining];
    }

    /**
     * Locks the original invoice row (the caller already holds the counters it needs) and
     * returns it with what is still uncredited: its total + every supplementary invoice on
     * it − every credit note against it. Read under the lock, so it cannot move meanwhile.
     *
     * @return array{invoice: Invoice, remaining: Money}
     */
    public function lockInvoice(int $invoiceId): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Credit notes are issued inside a transaction only.');
        }

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

        $credited = CreditNote::query()->where('invoice_id', $invoice->id)->get(['total'])
            ->reduce(static fn (Money $sum, CreditNote $note): Money => $sum->plus($note->total), Money::zero());
        $supplementary = Invoice::query()->where('original_invoice_id', $invoice->id)->get(['total'])
            ->reduce(static fn (Money $sum, Invoice $s): Money => $sum->plus($s->total), Money::zero());

        return ['invoice' => $invoice, 'remaining' => $invoice->total->plus($supplementary)->minus($credited)];
    }

    /**
     * Lines and totals that credit everything still standing on a locked original invoice
     * (cancellation after issue, courier return): an invoice never credited and never
     * supplemented is credited line for line with its discount, delivery charge and tax, so
     * the credit note mirrors it; otherwise one `balance` line for the remainder. Whatever
     * the stored parts do not explain is an explicit adjustment, never hidden.
     *
     * @return array{lines: list<array<string, mixed>>, totals: array{subtotal: Money, discount: Money, shipping: Money, tax: Money, adjustment: Money, total: Money}}
     */
    public static function fullBalance(Invoice $locked, Money $remaining): array
    {
        $untouched = $remaining->equals($locked->total)
            && ! CreditNote::query()->where('invoice_id', $locked->id)->exists()
            && ! Invoice::query()->where('original_invoice_id', $locked->id)->exists();

        if ($untouched) {
            $lines = array_map(static fn (array $line): array => [
                'kind' => 'item',
                'name' => $line['name'] ?? '',
                'name_bn' => $line['name_bn'] ?? null,
                'variant' => $line['variant'] ?? null,
                'sku' => $line['sku'] ?? null,
                'quantity' => (int) ($line['quantity'] ?? 0),
                'unit_price' => (string) ($line['unit_price'] ?? '0.00'),
                'amount' => (string) ($line['line_total'] ?? '0.00'),
            ], $locked->lines);
            $parts = ['subtotal' => $locked->subtotal, 'discount' => $locked->discount, 'shipping' => $locked->shipping, 'tax' => $locked->tax];
        } else {
            $lines = [self::balanceLine($locked->number, $remaining)];
            $parts = ['subtotal' => $remaining, 'discount' => Money::zero(), 'shipping' => Money::zero(), 'tax' => Money::zero()];
        }
        $explained = $parts['subtotal']->minus($parts['discount'])->plus($parts['shipping'])->plus($parts['tax']);

        return ['lines' => $lines, 'totals' => $parts + ['adjustment' => $remaining->minus($explained), 'total' => $remaining]];
    }

    /** @return array<string, mixed> one line crediting the remainder of `$invoiceNumber` */
    public static function balanceLine(string $invoiceNumber, Money $amount): array
    {
        return [
            'kind' => 'balance',
            'name' => $invoiceNumber,
            'name_bn' => null,
            'variant' => null,
            'sku' => null,
            'quantity' => 1,
            'unit_price' => $amount->toDecimal(),
            'amount' => $amount->toDecimal(),
        ];
    }

    /**
     * Takes the next `CN` number and writes the credit note. `total` must not exceed
     * `remaining` (and must be positive) — otherwise nothing is written.
     *
     * @param  array{year: int, next: int}  $counter
     * @param  list<array<string, mixed>>  $lines
     * @param  array{subtotal: Money, discount: Money, shipping: Money, tax: Money, adjustment: Money, total: Money}  $totals
     *
     * @throws CreditNoteExceedsInvoice
     */
    public function write(
        array $counter,
        Invoice $invoice,
        Money $remaining,
        CreditNoteReason $reason,
        int $sourceId,
        ?string $sourceReference,
        ?string $note,
        array $lines,
        array $totals,
        ?int $userId,
    ): CreditNote {
        $total = $totals['total'];
        if ($total->greaterThan($remaining) || ! $total->greaterThan(Money::zero())) {
            throw new CreditNoteExceedsInvoice($invoice->number, $total->toDecimal(), $remaining->toDecimal());
        }

        $number = $this->numbers->take(CreditNoteSeries::CreditNote, $counter['year'], $counter['next']);

        return CreditNote::query()->create([
            'series' => CreditNoteSeries::CreditNote->series(),
            'year' => $counter['year'],
            'sequence' => $counter['next'],
            'number' => $number,
            'invoice_id' => $invoice->id,
            'order_id' => $invoice->order_id,
            'invoice_number' => $invoice->number,
            'invoice_issued_at' => $invoice->issued_at,
            'reason' => $reason,
            'source_type' => $reason->sourceType(),
            'source_id' => $sourceId,
            'source_reference' => $sourceReference !== null ? mb_substr($sourceReference, 0, 64) : null,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
            'issued_to' => $invoice->issued_to,
            'issued_by' => $this->company->snapshot(),
            'lines' => $lines,
            'currency' => $invoice->currency,
            'subtotal' => $totals['subtotal'],
            'discount' => $totals['discount'],
            'shipping' => $totals['shipping'],
            'tax' => $totals['tax'],
            'adjustment' => $totals['adjustment'],
            'total' => $total,
            'amount_in_words' => AmountInWords::english($total),
            'amount_in_words_bn' => AmountInWords::bangla($total),
            'issued_at' => now(),
            'template' => CreditNote::TEMPLATE_STANDARD,
            'created_by' => $userId,
        ]);
    }
}
