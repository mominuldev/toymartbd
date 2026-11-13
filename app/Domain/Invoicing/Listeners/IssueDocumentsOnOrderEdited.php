<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Listeners;

use App\Domain\Invoicing\Actions\IssueOrderEditDocuments;
use App\Domain\Ordering\Events\OrderEdited;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ordering → Invoicing (rule 2, P8 batch 3): an order edited after its invoice was issued gets
 * a credit note for the reduction and a supplementary invoice for the increase. OrderEdited
 * is held until the edit commits; this runs after it, so a failure here can never undo the
 * edit — it is reported and logged (ids only, rule 20). It walks every edit of the order made
 * after the invoice, so a replay (or the next edit) catches up a missed one; each edit is
 * idempotent (UNIQUE source).
 */
final readonly class IssueDocumentsOnOrderEdited
{
    public function __construct(private IssueOrderEditDocuments $issue) {}

    public function handle(OrderEdited $event): void
    {
        try {
            $this->issue->forOrder($event->orderId);
        } catch (Throwable $e) {
            Log::warning('invoice.order_edit_documents_failed', ['order_id' => $event->orderId, 'exception' => $e::class]);
            report($e);
        }
    }
}
