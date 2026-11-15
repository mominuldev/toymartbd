<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * What WhatsApp reported for a template it accepted (`statuses[].status` on the Cloud API
 * webhook). Only moves forward — sent → delivered → read — or to failed before delivery: Meta
 * does not promise ordering, so a late `delivered` never undoes a `read`.
 */
enum WhatsAppDeliveryStatus: string
{
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    public function rank(): int
    {
        return match ($this) {
            self::Sent => 1,
            self::Delivered => 2,
            self::Read => 3,
            self::Failed => 4,
        };
    }

    /** May a webhook move a log from `$current` (null = nothing heard yet) to `$next`? */
    public static function canAdvance(?self $current, self $next): bool
    {
        if ($current === null) {
            return true;
        }
        if ($current === self::Failed || $current === self::Read) {
            return false;
        }

        return $next === self::Failed ? $current === self::Sent : $next->rank() > $current->rank();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
