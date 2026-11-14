<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Enums;

/** A timeline line in a thread; the admin translates the code (rule 35). */
enum SystemEvent: string
{
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case Resolved = 'resolved';
    case Reopened = 'reopened';
}
