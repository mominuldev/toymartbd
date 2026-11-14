<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Exceptions\InvoiceNotIssuable;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\AmountInWords;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Invoicing\Services\InvoiceNumberGenerator;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Issues the customer invoice for one order — at most once (PRD N-13). Idempotent: a
 * second call, from anyone, returns the invoice already issued. This is the order's
 * `original` invoice; an upward edit after it gets a supplementary invoice
 * (IssueOrderEditDocuments, P8 batch 3).
 *
 * WHEN: the docs do not fix the moment, so it is issued on the first print (single or bulk)
 * of a non-cancelled order. Printing a cancelled order that has no invoice is refused
 * (`cancelled`); one issued before the cancellation stays and reprints, corrected by a
 * credit note (IssueCancellationCreditNote, P8 batch 2).
 *
 * HOW: one transaction — the series counter is locked FIRST, so every issuer serialises on
 * it; then the order row (P8 batch 3: an EditOrder cannot commit between the snapshot and
 * `order_event_id`); then the order is checked for an invoice under those locks (a
 * concurrent issuer for the same order finds the one just committed); then the number is
 * taken and the row written. A failure anywhere rolls the counter back with the row:
 * gap-free. Lock order: `INV` counter → order row → invoice rows. EditOrder takes the order
 * row and never a counter, so the two cannot deadlock.
 *
 * WHAT: company details from {@see CompanyDetails::snapshot()}, the ship-to block, the
 * lines and every total COPIED from the order (Ordering computed them; nothing is priced
 * here, rule 1). The row is immutable afterwards. `order_event_id` records the order's last
 * order_events id: an edit with a higher id came after the invoice.
 */
final readonly class IssueSalesInvoice
{
    public function __construct(
        private InvoiceNumberGenerator $numbers,
        private CompanyDetails $company,
    ) {}

    public function handle(Order $order, ?int $userId = null): Invoice
    {
        $existing = self::existing($order->id);
        if ($existing !== null) {
            return $existing;
        }

        $this->numbers->prepare(InvoiceType::Sales);

        // 3 attempts: a MySQL deadlock (40001) retries the whole issue, never half of it.
        return DB::transaction(function () use ($order, $userId): Invoice {
            $counter = $this->numbers->lock(InvoiceType::Sales);
            /** @var Order $fresh */
            $fresh = Order::query()->lockForUpdate()->findOrFail($order->id);

            $existing = Invoice::query()->where('type', InvoiceType::Sales->value)->where('kind', InvoiceKind::Original->value)
                ->where('order_id', $order->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            if ($fresh->status === OrderStatus::Cancelled) {
                throw InvoiceNotIssuable::cancelledOrder($fresh->number);
            }
            $fresh->load(['items', 'customer']);

            $number = $this->numbers->take(InvoiceType::Sales, $counter['year'], $counter['next']);

            return Invoice::query()->create([
                'type' => InvoiceType::Sales,
                'kind' => InvoiceKind::Original,
                'series' => InvoiceType::Sales->series(),
                'year' => $counter['year'],
                'sequence' => $counter['next'],
                'number' => $number,
                'order_id' => $fresh->id,
                'order_event_id' => self::lastOrderEventId($fresh->id),
                'reference' => $fresh->number,
                'issued_by' => $this->company->snapshot(),
                'issued_to' => self::issuedTo($fresh),
                'lines' => $fresh->items
                    ->filter(static fn (OrderItem $item): bool => $item->quantity > 0)
                    ->map(static fn (OrderItem $item): array => [
                        'name' => $item->product_name,
                        'name_bn' => $item->product_name_bn,
                        'variant' => $item->variant_name,
                        'sku' => $item->sku,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price->toDecimal(),
                        'discount' => $item->discount->toDecimal(),
                        'line_total' => $item->line_total->toDecimal(),
                    ])->values()->all(),
                'currency' => $fresh->currency,
                'subtotal' => $fresh->subtotal,
                'discount' => $fresh->discount_total,
                'shipping' => $fresh->shipping_charge,
                'tax' => $fresh->tax_total,
                'total' => $fresh->grand_total,
                'paid' => $fresh->paid_total,
                'due' => $fresh->due_total,
                'payment_method' => $fresh->payment_method,
                'amount_in_words' => AmountInWords::english($fresh->grand_total),
                'amount_in_words_bn' => AmountInWords::bangla($fresh->grand_total),
                'issued_at' => now(),
                'template' => Invoice::TEMPLATE_STANDARD,
                'created_by' => $userId,
            ]);
        }, 3);
    }

    /** The order's last timeline row id — the point the snapshot reflects (null: none yet). */
    public static function lastOrderEventId(int $orderId): ?int
    {
        $id = OrderEvent::query()->where('order_id', $orderId)->max('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * The customer / ship-to block as it stands on the order (also used for a supplementary
     * invoice, P8 batch 3). Full PII lives only on the invoice row; the API masks it.
     *
     * @return array<string, mixed>
     */
    public static function issuedTo(Order $order): array
    {
        return [
            'customer_id' => $order->customer_id,
            'name' => $order->shipping_name,
            'phone' => $order->shipping_phone,
            'alt_phone' => $order->shipping_alt_phone,
            'address' => $order->shipping_address,
            'area' => $order->shipping_area,
            'upazila' => $order->shipping_upazila,
            'district' => $order->shipping_district,
            'division' => $order->shipping_division,
            'landmark' => $order->shipping_landmark,
            'postcode' => $order->shipping_postcode,
            'preferred_language' => $order->customer->preferred_language === 'en' ? 'en' : 'bn',
            'placed_at' => $order->placed_at?->toIso8601String(),
            'coupon_code' => $order->coupon_code,
            'note' => $order->customer_note,
        ];
    }

    private static function existing(int $orderId): ?Invoice
    {
        return Invoice::query()->where('type', InvoiceType::Sales->value)->where('kind', InvoiceKind::Original->value)->where('order_id', $orderId)->first();
    }
}
