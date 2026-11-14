<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Enums;

/** What one evaluation means for the owner: nothing, a new alert, a reminder, or "resolved". */
enum AlertDecision: string
{
    case None = 'none';
    case Fire = 'fire';
    case Remind = 'remind';
    case Resolve = 'resolve';

    public function notifies(): bool
    {
        return $this !== self::None;
    }
}
