<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Listeners;

use App\Domain\Invoicing\Actions\IssueCancellationCreditNote;
use App\Domain\Ordering\Events\OrderCancelled;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ordering → Invoicing (rule 2): an order cancelled after its invoice was issued gets a
 * credit note. OrderCancelled is held until the cancellation commits; this runs after it,
 * so a failure here can never undo the cancellation — it is reported and logged (ids only,
 * rule 20) for staff, and replaying the event later is safe (idempotent).
 */
final readonly class IssueCreditNoteOnOrderCancelled
{
    public function __construct(private IssueCancellationCreditNote $issue) {}

    public function handle(OrderCancelled $event): void
    {
        try {
            $this->issue->handle($event->orderId, $event->userId);
        } catch (Throwable $e) {
            Log::warning('credit_note.cancellation_failed', ['order_id' => $event->orderId, 'exception' => $e::class]);
            report($e);
        }
    }
}
