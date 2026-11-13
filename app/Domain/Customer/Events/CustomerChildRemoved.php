<?php

declare(strict_types=1);

namespace App\Domain\Customer\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A parent (or staff) removed a child (P8 batch 3). The row is already deleted. Notification
 * listens to blank the child's name from the birthday-reminder messages it logged — the parent
 * asked for the data to go, so it goes from message payloads too. Carries ids only (rule 20).
 */
final readonly class CustomerChildRemoved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $customerId,
        public int $childId,
    ) {}
}
