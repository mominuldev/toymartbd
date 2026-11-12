<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * queued → sent, or queued → (retries) → dead_lettered after the last try.
 * `failed` is a permanent provider refusal on a channel that has a fallback (WhatsApp →
 * SMS). `skipped` records a message not sent because the customer opted out or had no
 * address on that channel — kept so "opt-out respected" is provable.
 */
enum NotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case DeadLettered = 'dead_lettered';
    case Skipped = 'skipped';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
