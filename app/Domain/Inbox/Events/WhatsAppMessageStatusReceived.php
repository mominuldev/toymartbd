<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * A verified WhatsApp `statuses` webhook entry for a message that is NOT an inbox message —
 * i.e. one the store sent from somewhere else on the same WhatsApp number (an order update
 * template sent by Notification). The webhook is the Inbox's, so the Inbox raises this and
 * Notification records it on its own `notification_logs` row (rule 2: cross-domain writes go
 * through events). Primitive values only: DomainEvents may not depend on either domain.
 *
 * `status` is Meta's own word: sent | delivered | read | failed. `errorCode` / `errorTitle` are
 * from `errors[0]` on a failure (a provider description, never a phone number).
 */
final readonly class WhatsAppMessageStatusReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $providerMessageId,
        public string $status,
        public Carbon $occurredAt,
        public ?string $errorCode = null,
        public ?string $errorTitle = null,
    ) {}
}
