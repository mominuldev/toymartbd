<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Enums;

enum MessageDirection: string
{
    /** From the customer. */
    case In = 'in';
    /** From us — a reply, or (never sent) a note or a system line. */
    case Out = 'out';
}
