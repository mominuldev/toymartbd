<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Enums;

/**
 * `text` is a real message on the channel. `note` is an internal note and `system` a
 * timeline line (assigned, resolved, reopened): both live only here and are NEVER sent.
 */
enum MessageKind: string
{
    case Text = 'text';
    case Note = 'note';
    case System = 'system';

    public function leavesTheSystem(): bool
    {
        return $this === self::Text;
    }
}
