<?php

declare(strict_types=1);

namespace App\Domain\Notification\Support;

use App\Support\DhakaDate;
use DateTimeInterface;

/** Is a moment inside a Dhaka quiet window `HH:MM`–`HH:MM` (which may wrap midnight)? */
final class QuietHours
{
    public static function contains(DateTimeInterface $now, string $from, string $to): bool
    {
        if ($from === '' || $to === '' || $from === $to) {
            return false;
        }
        $t = DhakaDate::local($now)->format('H:i');

        return $from < $to ? ($t >= $from && $t < $to) : ($t >= $from || $t < $to);
    }
}
