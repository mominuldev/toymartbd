<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Invoicing\Models\CreditNote;
use App\Http\Resources\ApiResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * An issued credit note in a list (P8 batch 2) — the credit-note list and the credit notes
 * of an invoice. Figures are the snapshot taken at issue, decimal strings (rule 1). No
 * phone or address.
 *
 * @property CreditNote $resource
 */
#[SchemaName('CreditNoteListItem')]
final class CreditNoteListItemResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $note = $this->resource;
        $user = $request->user();

        return [
            'id' => $note->id,
            /**
             * `CN-26-000001`.
             *
             * @var string
             */
            'number' => $note->number,
            /** @var int */
            'invoice_id' => $note->invoice_id,
            /**
             * The invoice credited, e.g. `INV-26-000001`.
             *
             * @var string
             */
            'invoice_number' => $note->invoice_number,
            /** @var int */
            'order_id' => $note->order_id,
            /**
             * Why it was issued: the order was cancelled after the invoice, a refund was paid, the
             * order was edited down after issue, or the courier returned it (RTO; P8 batch 3).
             *
             * @var 'order_cancelled'|'refund'|'order_edited'|'courier_return'
             */
            'reason' => $note->reason->value,
            /**
             * The order number (cancellation, edit, courier return) or the RMA number (refund), as issued.
             *
             * @var string|null
             */
            'source_reference' => $note->source_reference,
            /**
             * Customer name, as on the invoice.
             *
             * @var string
             */
            'issued_to_name' => (string) ($note->issued_to['name'] ?? ''),
            /** @var string */
            'total' => $note->total->toDecimal(),
            /**
             * ISO 8601, UTC.
             *
             * @var string
             */
            'issued_at' => $note->issued_at->toIso8601String(),
            /** Whether this user may print it (orders.view + customers.view_pii). */
            'can_print' => $user instanceof User && $user->can('print', $note),
        ];
    }
}
