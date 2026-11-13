<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Exceptions\InvoiceImmutable;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An issued invoice (DATABASE-DESIGN §3.5, PRD N-13): customer (`sales`, one per order) or
 * supplier (`purchase`, one per PO). Written once by IssueSalesInvoice / IssuePurchaseInvoice
 * and never changed or deleted — a reprint renders exactly this row, with the company,
 * customer and line details that were current when it was issued.
 *
 * `issued_by`: name_en, name_bn, address_en, address_bn, hotline, email, website,
 *   trade_licence, bin, logo_media_id, footer_en, footer_bn, placeholders (list of unset keys).
 * `issued_to` (sales): name, phone (E.164), address, area, upazila, district, division,
 *   landmark, postcode, customer_id, preferred_language. (purchase): supplier_id, name,
 *   contact_person, phone, email, address, tin, supplier_invoice_no.
 * `lines`: list of {name, name_bn, variant, sku, quantity, unit_price, discount, line_total}
 *   — money as decimal strings, copied, never recomputed. A supplementary invoice's lines
 *   (P8 batch 3) are the edit's INCREASES: `kind` `item` (added/increased quantity) or
 *   `adjustment` (the line's amount rose with no added quantity), `line_total` the increase.
 *
 * `kind` (P8 batch 3): `original` — the order's one invoice, issued on first print — or
 * `supplementary` — the increase of an order edited upwards after that, numbered from the
 * same `INV` series, pointing at `original_invoice_id`, with `source_type` `order_edit` and
 * `source_id` the edit's `order_events` row (UNIQUE: one per edit). Credit notes always
 * reference the original; {@see self::balance()} on the original covers the supplementary
 * invoices too. `order_event_id`: the order's last order_events id the snapshot reflects.
 *
 * @property int $id
 * @property InvoiceType $type
 * @property InvoiceKind $kind
 * @property int|null $original_invoice_id
 * @property string|null $source_type
 * @property int|null $source_id
 * @property int|null $order_event_id
 * @property string $series
 * @property int $year
 * @property int $sequence
 * @property string $number
 * @property int|null $order_id
 * @property int|null $purchase_id
 * @property string $reference
 * @property array<string, mixed> $issued_to
 * @property array<string, mixed> $issued_by
 * @property list<array<string, mixed>> $lines
 * @property string $currency
 * @property Money $subtotal
 * @property Money $discount
 * @property Money $shipping
 * @property Money $tax
 * @property Money $total
 * @property Money $paid
 * @property Money $due
 * @property string|null $payment_method
 * @property string|null $amount_in_words
 * @property string|null $amount_in_words_bn Bangla words (P9); null on documents issued before it
 * @property Carbon $issued_at
 * @property Carbon|null $due_at
 * @property string|null $pdf_path
 * @property string $template
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CreditNote> $creditNotes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Invoice> $supplementaryInvoices
 * @property-read Invoice|null $originalInvoice
 */
final class Invoice extends Model
{
    /** The only layout today. A VAT/Mushak format (PRD N-14, P2) would be a new value here. */
    public const string TEMPLATE_STANDARD = 'standard';

    /** @var list<string> `original_order_key` is a generated column, never written. */
    protected $guarded = ['id', 'original_order_key'];

    /** @var array<string, mixed> the column default, so a freshly created row reads it too */
    protected $attributes = ['kind' => 'original'];

    protected static function booted(): void
    {
        self::updating(static fn (self $invoice): never => throw InvoiceImmutable::make($invoice->number));
        self::deleting(static fn (self $invoice): never => throw InvoiceImmutable::make($invoice->number));
    }

    /**
     * Credit notes issued against this (customer) invoice, oldest first (P8 batch 2).
     *
     * @return HasMany<CreditNote, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class)->orderBy('id');
    }

    /**
     * The supplementary invoices issued on top of this original, oldest first (P8 batch 3).
     *
     * @return HasMany<Invoice, $this>
     */
    public function supplementaryInvoices(): HasMany
    {
        return $this->hasMany(self::class, 'original_invoice_id')->orderBy('id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    public function isSupplementary(): bool
    {
        return $this->kind === InvoiceKind::Supplementary;
    }

    /** Σ credit-note totals — what has been credited so far. Needs `creditNotes` (loads it if not). */
    public function credited(): Money
    {
        return $this->creditNotes->reduce(static fn (Money $sum, CreditNote $note): Money => $sum->plus($note->total), Money::zero());
    }

    /**
     * What has been invoiced: an original's total plus every supplementary invoice on it
     * (P8 batch 3); a supplementary invoice's own total. Needs `supplementaryInvoices`.
     */
    public function invoicedTotal(): Money
    {
        if ($this->isSupplementary()) {
            return $this->total;
        }

        return $this->supplementaryInvoices->reduce(static fn (Money $sum, Invoice $s): Money => $sum->plus($s->total), $this->total);
    }

    /**
     * Everything invoiced less everything credited: what still stands. Never negative by
     * construction. Credit notes reference the original, so a supplementary invoice's own
     * balance is simply its total; the order's balance is the original's.
     */
    public function balance(): Money
    {
        return $this->invoicedTotal()->minus($this->credited());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'kind' => InvoiceKind::class,
            'original_invoice_id' => 'integer',
            'source_id' => 'integer',
            'order_event_id' => 'integer',
            'year' => 'integer',
            'sequence' => 'integer',
            'order_id' => 'integer',
            'purchase_id' => 'integer',
            'issued_to' => 'array',
            'issued_by' => 'array',
            'lines' => 'array',
            'subtotal' => MoneyCast::class,
            'discount' => MoneyCast::class,
            'shipping' => MoneyCast::class,
            'tax' => MoneyCast::class,
            'total' => MoneyCast::class,
            'paid' => MoneyCast::class,
            'due' => MoneyCast::class,
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }
}
