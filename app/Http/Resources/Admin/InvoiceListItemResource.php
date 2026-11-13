<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Invoicing\Models\Invoice;
use App\Http\Resources\ApiResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * An issued invoice in the list (P8, N-13). Figures are the snapshot taken at issue, as
 * decimal strings (rule 1 — the admin only formats). No phone or address in the list.
 * Credit notes (P8 batch 2) ride along with what has been credited and the balance;
 * load `creditNotes` with the invoice to avoid one query per row.
 *
 * @property Invoice $resource
 */
#[SchemaName('InvoiceListItem')]
final class InvoiceListItemResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $invoice = $this->resource;
        $user = $request->user();

        return [
            'id' => $invoice->id,
            /** @var 'sales'|'purchase' */
            'type' => $invoice->type->value,
            /**
             * `INV-26-000001` (customer) or `PINV-26-000001` (supplier).
             *
             * @var string
             */
            'number' => $invoice->number,
            /**
             * The order number (customer) or PO number (supplier) at issue.
             *
             * @var string
             */
            'reference' => $invoice->reference,
            /** @var int|null */
            'order_id' => $invoice->order_id,
            /** @var int|null */
            'purchase_id' => $invoice->purchase_id,
            /**
             * Customer or supplier name, as issued.
             *
             * @var string
             */
            'issued_to_name' => (string) ($invoice->issued_to['name'] ?? ''),
            /** @var string */
            'total' => $invoice->total->toDecimal(),
            /** @var string */
            'paid' => $invoice->paid->toDecimal(),
            /** @var string */
            'due' => $invoice->due->toDecimal(),
            /** @var string|null */
            'payment_method' => $invoice->payment_method,
            /**
             * Required company fields that were still placeholders when this was issued.
             *
             * @var list<string>
             */
            'placeholders' => array_values(array_map(strval(...), (array) ($invoice->issued_by['placeholders'] ?? []))),
            /**
             * ISO 8601, UTC.
             *
             * @var string
             */
            'issued_at' => $invoice->issued_at->toIso8601String(),
            /** Whether this user may print it (customer: orders.view + customers.view_pii). */
            'can_print' => $user instanceof User && $user->can('print', $invoice),
            /**
             * Σ credit notes issued against this invoice (P8 batch 2); `0.00` when none.
             *
             * @var string
             */
            'credited' => $invoice->credited()->toDecimal(),
            /**
             * The invoice total less everything credited — what still stands.
             *
             * @var string
             */
            'balance' => $invoice->balance()->toDecimal(),
            /**
             * `none` (nothing credited), `partial`, or `full` (balance is zero).
             *
             * @var 'none'|'partial'|'full'
             */
            'credit_status' => match (true) {
                $invoice->credited()->isZero() => 'none',
                $invoice->balance()->isZero() || $invoice->balance()->isNegative() => 'full',
                default => 'partial',
            },
            /** Credit notes issued against this invoice, oldest first. */
            'credit_notes' => CreditNoteListItemResource::collection($invoice->creditNotes),
        ];
    }
}
