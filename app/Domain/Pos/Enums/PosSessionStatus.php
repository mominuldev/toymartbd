<?php

declare(strict_types=1);

namespace App\Domain\Pos\Enums;

/** `pos_sessions.status`: a drawer is open until it is counted and closed, once. */
enum PosSessionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
