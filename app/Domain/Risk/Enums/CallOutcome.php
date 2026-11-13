<?php

declare(strict_types=1);

namespace App\Domain\Risk\Enums;

/** What happened on a verification call (risk_verification_calls.outcome). Logging one never moves the order. */
enum CallOutcome: string
{
    case Confirmed = 'confirmed';
    case NoAnswer = 'no_answer';
    case SwitchedOff = 'switched_off';
    case WrongNumber = 'wrong_number';
    case CallBack = 'call_back';
    case Declined = 'declined';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
