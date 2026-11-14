<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Enums;

/**
 * An outbound message's delivery state. `queued` until the send job runs; then the
 * provider's own statuses (WhatsApp `statuses[].status`, Messenger delivery/read
 * events). Only moves forward, except to `failed`.
 */
enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Sent => 1,
            self::Delivered => 2,
            self::Read => 3,
            self::Failed => 4,
        };
    }

    /** May a status update move a message from this state to `$next`? */
    public function canAdvanceTo(self $next): bool
    {
        if ($this === self::Failed || $this === self::Read) {
            return false;
        }

        return $next === self::Failed ? $this !== self::Delivered : $next->rank() > $this->rank();
    }
}
