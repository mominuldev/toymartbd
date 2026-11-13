<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Listeners;

use App\Domain\Invoicing\Actions\IssueCourierReturnCreditNote;
use App\Domain\Ordering\Events\OrderReturned;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ordering → Invoicing (rule 2, P8 batch 3): an invoiced order the courier returned to us
 * (RTO) gets a credit note for the invoice's whole remaining balance. OrderReturned is held
 * until the return commits; a failure here never undoes it — reported and logged (ids only).
 * A customer return also ends in `returned`; the Action tells them apart and leaves that one
 * to its refund's credit note. Replaying the event is safe (idempotent).
 */
final readonly class IssueCreditNoteOnOrderReturned
{
    public function __construct(private IssueCourierReturnCreditNote $issue) {}

    public function handle(OrderReturned $event): void
    {
        try {
            $this->issue->handle($event->orderId, $event->userId);
        } catch (Throwable $e) {
            Log::warning('credit_note.courier_return_failed', ['order_id' => $event->orderId, 'exception' => $e::class]);
            report($e);
        }
    }
}
