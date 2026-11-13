<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Exceptions\InvoiceNotIssuable;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\AmountInWords;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Invoicing\Services\InvoiceNumberGenerator;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseItem;
use Illuminate\Support\Facades\DB;

/**
 * Issues the supplier (purchase) invoice for one PO — the PO document we hand the supplier,
 * numbered `PINV-YY-NNNNNN` — at most once (PRD N-13). Same locking and snapshot rules as
 * {@see IssueSalesInvoice}. Only a PO that has been placed (not a draft, not cancelled) can
 * be issued; the first print issues it.
 *
 * Lines are copied from the PO: product + variant names and SKU as they are now, ordered
 * quantity, unit cost (4 decimals, as Purchasing stores it), discount and line total; the
 * totals from the PO header. Nothing is recomputed (rule 1).
 */
final readonly class IssuePurchaseInvoice
{
    public function __construct(
        private InvoiceNumberGenerator $numbers,
        private CompanyDetails $company,
    ) {}

    public function handle(Purchase $purchase, ?int $userId = null): Invoice
    {
        $existing = Invoice::query()->where('type', InvoiceType::Purchase->value)->where('purchase_id', $purchase->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $this->numbers->prepare(InvoiceType::Purchase);

        // 3 attempts: a MySQL deadlock (40001) retries the whole issue, never half of it.
        return DB::transaction(function () use ($purchase, $userId): Invoice {
            $counter = $this->numbers->lock(InvoiceType::Purchase);

            $existing = Invoice::query()->where('type', InvoiceType::Purchase->value)->where('purchase_id', $purchase->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            /** @var Purchase $fresh */
            $fresh = Purchase::query()->with(['items.variant.product', 'supplier'])->findOrFail($purchase->id);
            if ($fresh->number === null || in_array($fresh->status, [PurchaseStatus::Draft, PurchaseStatus::Cancelled], true)) {
                throw InvoiceNotIssuable::purchaseNotOrdered($fresh->number ?? '#'.$fresh->id, $fresh->status->value);
            }

            $number = $this->numbers->take(InvoiceType::Purchase, $counter['year'], $counter['next']);
            $supplier = $fresh->supplier;

            return Invoice::query()->create([
                'type' => InvoiceType::Purchase,
                'series' => InvoiceType::Purchase->series(),
                'year' => $counter['year'],
                'sequence' => $counter['next'],
                'number' => $number,
                'purchase_id' => $fresh->id,
                'reference' => $fresh->number,
                'issued_by' => $this->company->snapshot(),
                'issued_to' => [
                    'supplier_id' => $supplier->id,
                    'name' => $supplier->name,
                    'contact_person' => $supplier->contact_person,
                    'phone' => $supplier->phone,
                    'email' => $supplier->email,
                    'address' => $supplier->address,
                    'tin' => $supplier->tin,
                    'supplier_invoice_no' => $fresh->supplier_invoice_no,
                    'order_date' => $fresh->order_date->toDateString(),
                    'expected_date' => $fresh->expected_date?->toDateString(),
                    'note' => $fresh->note,
                    // `shipping` below is these three together; kept apart for the PDF breakdown.
                    'costs' => [
                        'shipping_cost' => $fresh->shipping_cost->toDecimal(),
                        'customs_duty' => $fresh->customs_duty->toDecimal(),
                        'other_cost' => $fresh->other_cost->toDecimal(),
                    ],
                ],
                'lines' => $fresh->items->map(static fn (PurchaseItem $item): array => [
                    'name' => $item->variant->product->name,
                    'name_bn' => $item->variant->product->name_bn,
                    'variant' => $item->variant->name,
                    'sku' => $item->variant->sku,
                    'quantity' => $item->quantity_ordered,
                    'unit_price' => $item->unit_cost,
                    'discount' => $item->discount->toDecimal(),
                    'line_total' => $item->line_total->toDecimal(),
                ])->values()->all(),
                'subtotal' => $fresh->subtotal,
                'discount' => $fresh->discount,
                'shipping' => $fresh->shipping_cost->plus($fresh->customs_duty)->plus($fresh->other_cost),
                'tax' => $fresh->tax,
                'total' => $fresh->total,
                'paid' => $fresh->paid_amount,
                'due' => $fresh->due_amount,
                'amount_in_words' => AmountInWords::english($fresh->total),
                'issued_at' => now(),
                'due_at' => $supplier->payment_terms_days > 0 ? $fresh->order_date->copy()->addDays($supplier->payment_terms_days) : null,
                'template' => Invoice::TEMPLATE_STANDARD,
                'created_by' => $userId,
            ]);
        }, 3);
    }
}
