<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Exceptions\InvoiceImmutable;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
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
 *   — money as decimal strings, copied, never recomputed.
 *
 * @property int $id
 * @property InvoiceType $type
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
 * @property Carbon $issued_at
 * @property Carbon|null $due_at
 * @property string|null $pdf_path
 * @property string $template
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CreditNote> $creditNotes
 */
final class Invoice extends Model
{
    /** The only layout today. A VAT/Mushak format (PRD N-14, P2) would be a new value here. */
    public const string TEMPLATE_STANDARD = 'standard';

    /** @var list<string> */
    protected $guarded = ['id'];

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

    /** Σ credit-note totals — what has been credited so far. Needs `creditNotes` (loads it if not). */
    public function credited(): Money
    {
        return $this->creditNotes->reduce(static fn (Money $sum, CreditNote $note): Money => $sum->plus($note->total), Money::zero());
    }

    /** The invoice total less everything credited: what still stands. Never negative by construction. */
    public function balance(): Money
    {
        return $this->total->minus($this->credited());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
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
