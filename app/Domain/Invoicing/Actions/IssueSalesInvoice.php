<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Exceptions\InvoiceNotIssuable;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\AmountInWords;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Invoicing\Services\InvoiceNumberGenerator;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Issues the customer invoice for one order — at most once (PRD N-13). Idempotent: a
 * second call, from anyone, returns the invoice already issued.
 *
 * WHEN: the docs do not fix the moment, so it is issued on the first print (single or bulk)
 * of a non-cancelled order. Printing a cancelled order that has no invoice is refused
 * (`cancelled`); one issued before the cancellation stays and reprints (a credit note is an
 * open item).
 *
 * HOW: one transaction — the series counter is locked FIRST, so every issuer serialises on
 * it; then the order is checked for an invoice under that lock (a concurrent issuer for the
 * same order finds the one just committed); then the number is taken and the row written.
 * A failure anywhere rolls the counter back with the row: gap-free.
 *
 * WHAT: company details from {@see CompanyDetails::snapshot()}, the ship-to block, the
 * lines and every total COPIED from the order (Ordering computed them; nothing is priced
 * here, rule 1). The row is immutable afterwards.
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

            $existing = Invoice::query()->where('type', InvoiceType::Sales->value)->where('order_id', $order->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            /** @var Order $fresh */
            $fresh = Order::query()->with(['items', 'customer'])->findOrFail($order->id);
            if ($fresh->status === OrderStatus::Cancelled) {
                throw InvoiceNotIssuable::cancelledOrder($fresh->number);
            }

            $number = $this->numbers->take(InvoiceType::Sales, $counter['year'], $counter['next']);

            return Invoice::query()->create([
                'type' => InvoiceType::Sales,
                'series' => InvoiceType::Sales->series(),
                'year' => $counter['year'],
                'sequence' => $counter['next'],
                'number' => $number,
                'order_id' => $fresh->id,
                'reference' => $fresh->number,
                'issued_by' => $this->company->snapshot(),
                'issued_to' => [
                    'customer_id' => $fresh->customer_id,
                    'name' => $fresh->shipping_name,
                    'phone' => $fresh->shipping_phone,
                    'alt_phone' => $fresh->shipping_alt_phone,
                    'address' => $fresh->shipping_address,
                    'area' => $fresh->shipping_area,
                    'upazila' => $fresh->shipping_upazila,
                    'district' => $fresh->shipping_district,
                    'division' => $fresh->shipping_division,
                    'landmark' => $fresh->shipping_landmark,
                    'postcode' => $fresh->shipping_postcode,
                    'preferred_language' => $fresh->customer->preferred_language === 'en' ? 'en' : 'bn',
                    'placed_at' => $fresh->placed_at?->toIso8601String(),
                    'coupon_code' => $fresh->coupon_code,
                    'note' => $fresh->customer_note,
                ],
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
                'issued_at' => now(),
                'template' => Invoice::TEMPLATE_STANDARD,
                'created_by' => $userId,
            ]);
        }, 3);
    }

    private static function existing(int $orderId): ?Invoice
    {
        return Invoice::query()->where('type', InvoiceType::Sales->value)->where('order_id', $orderId)->first();
    }
}
