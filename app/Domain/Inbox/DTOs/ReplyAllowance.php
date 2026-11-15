<?php

declare(strict_types=1);

namespace App\Domain\Inbox\DTOs;

use App\Domain\Inbox\Enums\ReplyState;
use Illuminate\Support\Carbon;

/**
 * A conversation's reply state at one moment. `closesAt`: when the current state ends —
 * the 24-hour window (`open`), the 7-day human-agent limit (`human_agent`) — or, once
 * closed (`template_only`, `closed`), when the last free-form window ended; null on live
 * chat or when the customer never wrote in.
 */
final readonly class ReplyAllowance
{
    public function __construct(
        public ReplyState $state,
        public ?Carbon $closesAt,
    ) {}
}
