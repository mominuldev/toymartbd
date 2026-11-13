<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\Enums\CreditNoteReason;
use App\Domain\Invoicing\Exceptions\CreditNoteImmutable;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An issued credit note (P8 batch 2): corrects an issued customer invoice after the order
 * was cancelled ({@see \App\Domain\Invoicing\Actions\IssueCancellationCreditNote}) or a refund
 * was paid on a return ({@see \App\Domain\Invoicing\Actions\IssueRefundCreditNote}).
 * Written once and never changed or deleted; the PDF re-renders this row.
 *
 * A document only — it never touches the ledger (the cancellation and the refund post their
 * own `financial_transactions`).
 *
 * `issued_by`: the company snapshot at the credit note's issue (CompanyDetails, same keys
 *   as Invoice). `issued_to`: the original invoice's recipient, copied.
 * `lines`: list of {kind: 'item'|'balance', name, name_bn, variant, sku, quantity,
 *   unit_price, amount} — money as decimal strings, copied from the invoice or the refund,
 *   never recomputed. A `balance` line credits the remainder of an invoice that was already
 *   partly credited (quantity 1, no sku).
 * `subtotal` (Σ line amounts) − `discount` + `shipping` + `tax` + `adjustment` (signed) = `total`.
 *
 * @property int $id
 * @property string $series
 * @property int $year
 * @property int $sequence
 * @property string $number
 * @property int $invoice_id
 * @property int $order_id
 * @property string $invoice_number
 * @property Carbon $invoice_issued_at
 * @property CreditNoteReason $reason
 * @property string $source_type
 * @property int $source_id
 * @property string|null $source_reference
 * @property string|null $note
 * @property array<string, mixed> $issued_to
 * @property array<string, mixed> $issued_by
 * @property list<array<string, mixed>> $lines
 * @property string $currency
 * @property Money $subtotal
 * @property Money $discount
 * @property Money $shipping
 * @property Money $tax
 * @property Money $adjustment
 * @property Money $total
 * @property string|null $amount_in_words
 * @property Carbon $issued_at
 * @property string $template
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Invoice $invoice
 */
final class CreditNote extends Model
{
    public const string TEMPLATE_STANDARD = 'standard';

    /** @var list<string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static fn (self $note): never => throw CreditNoteImmutable::make($note->number));
        self::deleting(static fn (self $note): never => throw CreditNoteImmutable::make($note->number));
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'sequence' => 'integer',
            'invoice_id' => 'integer',
            'order_id' => 'integer',
            'invoice_issued_at' => 'datetime',
            'reason' => CreditNoteReason::class,
            'source_id' => 'integer',
            'issued_to' => 'array',
            'issued_by' => 'array',
            'lines' => 'array',
            'subtotal' => MoneyCast::class,
            'discount' => MoneyCast::class,
            'shipping' => MoneyCast::class,
            'tax' => MoneyCast::class,
            'adjustment' => MoneyCast::class,
            'total' => MoneyCast::class,
            'issued_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }
}
