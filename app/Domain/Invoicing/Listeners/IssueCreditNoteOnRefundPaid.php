<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Listeners;

use App\Domain\Invoicing\Actions\IssueRefundCreditNote;
use App\Domain\Ordering\Events\RefundPaid;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ordering → Invoicing (rule 2): a refund paid on an invoiced order gets a credit note.
 * RefundPaid is held until the payout commits; a failure here (e.g. the refund would
 * over-credit the invoice) never undoes the payout — it is reported and logged (ids only)
 * for staff. Replaying the event is safe (idempotent).
 */
final readonly class IssueCreditNoteOnRefundPaid
{
    public function __construct(private IssueRefundCreditNote $issue) {}

    public function handle(RefundPaid $event): void
    {
        try {
            $this->issue->handle($event->refundId, $event->userId);
        } catch (Throwable $e) {
            Log::warning('credit_note.refund_failed', ['refund_id' => $event->refundId, 'order_id' => $event->orderId, 'exception' => $e::class]);
            report($e);
        }
    }
}
