<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Enums;

/** A suggested action is open until a person marks it done or not useful. */
enum ActionStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Dismissed = 'dismissed';
}
